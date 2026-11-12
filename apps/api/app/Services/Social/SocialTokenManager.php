<?php

namespace App\Services\Social;

use App\Enums\SocialAccountStatus;
use App\Exceptions\SocialIntegrationException;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Social\Data\TokenSet;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keeps stored credentials usable: renews tokens before they expire, verifies them with the
 * provider, tracks granted permissions, and moves accounts to `needs_reconnect` / `restricted`
 * (with a reason the user can read) when something can no longer be fixed automatically.
 */
class SocialTokenManager
{
    public function __construct(
        private readonly SocialIntegrationRegistry $integrations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Verify the connection: renew the token if it is due, ask the provider for the current
     * profile and permissions, and record the outcome on the account.
     *
     * @throws SocialIntegrationException when the account needs the user (reconnect) or the
     *                                    provider could not be reached; the account is updated first
     */
    public function check(SocialAccount $account): SocialAccount
    {
        $integration = $this->integrations->for($account->platform);
        $token = $this->tokenFor($account);

        try {
            if ($this->isRefreshDue($account, $token)) {
                $token = $this->renew($account, $token);
            }

            $snapshot = $integration->inspect($account, $token);
        } catch (SocialIntegrationException $e) {
            $this->recordFailure($account, $token, $e);

            throw $e;
        }

        $granted = $snapshot->grantedScopes ?? array_values((array) ($token->scopes ?? []));
        $missing = array_values(array_diff($integration->requiredScopes(), $granted));

        $account->forceFill([
            'name' => $snapshot->name,
            'username' => $snapshot->username,
            'avatar_url' => $snapshot->avatarUrl,
            'capabilities' => $snapshot->capabilities,
            'profile' => $snapshot->profile,
            'status' => $missing === [] ? SocialAccountStatus::Active : SocialAccountStatus::Restricted,
            'status_reason' => $missing === [] ? null : 'Missing permissions: '.implode(', ', array_map(fn (string $s): string => basename($s), $missing)).'.',
            'last_checked_at' => now(),
        ])->save();

        $token->forceFill([
            'scopes' => $granted,
            'expires_at' => $snapshot->tokenExpiresAt ?? $token->expires_at,
            'last_validated_at' => now(),
            'last_error' => null,
        ])->save();

        return $account->refresh();
    }

    /**
     * A token that is safe to use for an API call right now (renewed first when it is about to
     * expire). Used by publishing code.
     *
     * @throws SocialIntegrationException
     */
    public function freshToken(SocialAccount $account): SocialToken
    {
        $token = $this->tokenFor($account);

        if ($this->isRefreshDue($account, $token)) {
            try {
                $token = $this->renew($account, $token);
            } catch (SocialIntegrationException $e) {
                $this->recordFailure($account, $token, $e);

                throw $e;
            }
        }

        return $token;
    }

    /** Whether the stored token is expired or inside the provider's renewal window. */
    public function isRefreshDue(SocialAccount $account, SocialToken $token): bool
    {
        $ahead = config("social.providers.{$account->platform->slug()}.refresh_ahead_minutes");

        if ($ahead === null || $token->expires_at === null) {
            return false;
        }

        return $token->expires_at->lte(now()->addMinutes((int) $ahead));
    }

    /**
     * Renew under a lock so two workers never use the same one-time-ish refresh token at once.
     *
     * @throws SocialIntegrationException
     */
    private function renew(SocialAccount $account, SocialToken $token): SocialToken
    {
        $integration = $this->integrations->for($account->platform);

        if (! $integration->supportsRefresh()) {
            throw SocialIntegrationException::reconnectRequired($integration->label());
        }

        try {
            return Cache::lock("social-token-refresh:{$account->getKey()}", 30)->block(10, function () use ($account, $token, $integration): SocialToken {
                // Another worker may have renewed it while we waited for the lock.
                $current = $this->tokenFor($account);

                if ($current->updated_at?->gt($token->updated_at ?? now()->subYear()) && ! $this->isRefreshDue($account, $current)) {
                    return $current;
                }

                $new = $integration->refresh($account, $current);

                $this->store($account, $new, $current);

                $this->audit->record('social.token.refreshed', $this->owner($account), $account, ['platform' => $account->platform->value]);

                return $this->tokenFor($account);
            });
        } catch (LockTimeoutException) {
            throw SocialIntegrationException::unavailable($integration->label());
        }
    }

    /** Create or update the account's token row from a TokenSet. */
    public function store(SocialAccount $account, TokenSet $tokens, ?SocialToken $existing = null): SocialToken
    {
        $token = $existing ?? $account->tokens()->where('token_kind', 'access')->first() ?? new SocialToken;

        $token->forceFill([
            'social_account_id' => $account->getKey(),
            'token_kind' => 'access',
            'access_token' => $tokens->accessToken,
            'refresh_token' => $tokens->refreshToken ?? $token->refresh_token,
            'token_type' => $tokens->tokenType,
            'expires_at' => $tokens->expiresAt,
            'scopes' => $tokens->scopes,
            'provider_user_id' => $tokens->providerUserId ?? $token->provider_user_id,
            'last_error' => null,
        ])->save();

        return $token;
    }

    /** Queried explicitly: lazy-loading relations is disabled outside production. */
    private function owner(SocialAccount $account): User
    {
        return $account->user()->firstOrFail();
    }

    private function tokenFor(SocialAccount $account): SocialToken
    {
        $token = $account->tokens()->where('token_kind', 'access')->first();

        if ($token === null) {
            throw SocialIntegrationException::reconnectRequired($this->integrations->for($account->platform)->label());
        }

        return $token;
    }

    private function recordFailure(SocialAccount $account, SocialToken $token, SocialIntegrationException $e): void
    {
        $token->forceFill(['last_error' => $e->errorCode])->save();
        $account->forceFill(['last_checked_at' => now()]);

        if ($e->requiresReconnect()) {
            $account->forceFill([
                'status' => SocialAccountStatus::NeedsReconnect,
                'status_reason' => $e->getMessage(),
            ]);

            $this->audit->record('social.account.needs_reconnect', $this->owner($account), $account, [
                'platform' => $account->platform->value,
                'reason' => $e->errorCode,
            ]);
        } else {
            // Temporary problems (network, rate limits) leave the status alone but are visible in logs.
            Log::info('social.check_failed', ['account' => $account->ulid, 'code' => $e->errorCode]);
        }

        $account->save();
    }
}
