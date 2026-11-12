<?php

namespace App\Services\Social;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Exceptions\SocialIntegrationException;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Social\Data\DiscoveredAccount;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The connect / disconnect use cases. Controllers call this; it coordinates OAuth state, the
 * platform integration, persistence and audit logging.
 */
class SocialConnectionService
{
    public function __construct(
        private readonly SocialIntegrationRegistry $integrations,
        private readonly OAuthStateService $states,
        private readonly SocialTokenManager $tokens,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Start an OAuth authorization for the user.
     *
     * @return array{authorization_url: string, expires_at: CarbonInterface}
     *
     * @throws SocialIntegrationException when the platform's developer credentials are missing
     */
    public function begin(User $user, SocialPlatform $platform): array
    {
        $integration = $this->integrations->for($platform);

        if (! $integration->isConfigured()) {
            $this->audit->record('social.connect.unavailable', $user, null, ['platform' => $platform->value]);

            throw SocialIntegrationException::notConfigured($integration->label(), $integration->missingConfiguration());
        }

        $issued = $this->states->issue($user, $platform, $integration->usesPkce());

        $this->audit->record('social.connect.started', $user, null, ['platform' => $platform->value]);

        return [
            'authorization_url' => $integration->authorizationUrl($issued['state'], $issued['code_challenge']),
            'expires_at' => $issued['expires_at'],
        ];
    }

    /**
     * Finish an authorization: validate the state, exchange the code, store the accounts.
     *
     * @return Collection<int, SocialAccount>
     *
     * @throws SocialIntegrationException
     */
    public function complete(User $user, SocialPlatform $platform, string $state, ?string $code, ?string $providerError = null): Collection
    {
        $integration = $this->integrations->for($platform);

        try {
            $record = $this->states->consume($user, $platform, $state);

            if ($providerError !== null || $code === null || $code === '') {
                throw SocialIntegrationException::authorizationDenied($integration->label(), $providerError);
            }

            $discovered = $integration->completeAuthorization($code, $record->code_verifier);
        } catch (SocialIntegrationException $e) {
            $this->audit->record('social.connect.failed', $user, null, ['platform' => $platform->value, 'reason' => $e->errorCode]);

            throw $e;
        }

        $accounts = DB::transaction(fn () => array_map(
            fn (DiscoveredAccount $found): SocialAccount => $this->persist($user, $platform, $found),
            $discovered,
        ));

        foreach ($accounts as $account) {
            $this->audit->record('social.account.connected', $user, $account, ['platform' => $platform->value]);
        }

        return new Collection($accounts);
    }

    /** Remove the connection: revoke at the provider when possible, delete credentials, hide the account. */
    public function disconnect(User $user, SocialAccount $account): void
    {
        $integration = $this->integrations->for($account->platform);
        $token = $account->tokens()->where('token_kind', 'access')->first();

        if ($token !== null) {
            $integration->revoke($account, $token);
        }

        DB::transaction(function () use ($account): void {
            $account->tokens()->delete();
            $account->forceFill(['status' => SocialAccountStatus::Revoked, 'status_reason' => null])->save();
            $account->delete();
        });

        $this->audit->record('social.account.disconnected', $user, $account, ['platform' => $account->platform->value]);
    }

    /**
     * Best-effort revocation of every grant a user holds, used when their account is deleted. The
     * rows themselves disappear with the user (foreign keys cascade), so nothing is persisted here
     * and a provider outage never blocks the deletion.
     */
    public function revokeAllFor(User $user): void
    {
        $accounts = $user->socialAccounts()->with('tokens')->get();

        foreach ($accounts as $account) {
            $token = $account->tokens->firstWhere('token_kind', 'access');

            if ($token !== null) {
                $this->integrations->for($account->platform)->revoke($account, $token);
            }
        }
    }

    /** Create the account (or revive a disconnected one) and store its credentials. */
    private function persist(User $user, SocialPlatform $platform, DiscoveredAccount $found): SocialAccount
    {
        $snapshot = $found->snapshot;

        $account = SocialAccount::withTrashed()
            ->where('user_id', $user->getKey())
            ->where('platform', $platform->value)
            ->where('external_id', $snapshot->externalId)
            ->first() ?? new SocialAccount;

        if ($account->trashed()) {
            $account->restore();
        }

        $account->forceFill([
            'user_id' => $user->getKey(),
            'provider' => $platform->provider(),
            'platform' => $platform,
            'external_id' => $snapshot->externalId,
            'name' => $snapshot->name,
            'username' => $snapshot->username,
            'avatar_url' => $snapshot->avatarUrl,
            'capabilities' => $snapshot->capabilities,
            'profile' => $snapshot->profile,
            'status' => SocialAccountStatus::Active,
            'status_reason' => null,
            'last_checked_at' => now(),
        ]);

        if (! $account->exists) {
            $account->is_enabled = true;
        }

        $account->save();

        $this->tokens->store($account, $found->tokens);

        return $account;
    }
}
