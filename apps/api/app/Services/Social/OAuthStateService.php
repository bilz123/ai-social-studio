<?php

namespace App\Services\Social;

use App\Enums\SocialPlatform;
use App\Exceptions\SocialIntegrationException;
use App\Models\OAuthState;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * OAuth `state` handling (CSRF protection for the callback).
 *
 * A state is 256 bits of randomness, bound to one user and one platform, valid for a few minutes
 * and consumable exactly once. Only its SHA-256 hash is stored.
 */
class OAuthStateService
{
    /**
     * @return array{state: string, code_verifier: string|null, code_challenge: string|null, expires_at: CarbonInterface}
     */
    public function issue(User $user, SocialPlatform $platform, bool $pkce): array
    {
        $state = Str::random(64);
        $verifier = $pkce ? Str::random(96) : null;
        $expiresAt = now()->addMinutes((int) config('social.state_ttl_minutes'));

        (new OAuthState)->forceFill([
            'user_id' => $user->getKey(),
            'state_hash' => $this->hash($state),
            'platform' => $platform->value,
            'code_verifier' => $verifier,
            'expires_at' => $expiresAt,
        ])->save();

        return [
            'state' => $state,
            'code_verifier' => $verifier,
            'code_challenge' => $verifier !== null ? $this->challenge($verifier) : null,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Validate and burn a state. The conditional UPDATE makes consumption atomic, so two
     * concurrent callbacks with the same state cannot both succeed.
     *
     * @throws SocialIntegrationException when the state is unknown, expired, used, or not this user's
     */
    public function consume(User $user, SocialPlatform $platform, string $state): OAuthState
    {
        $hash = $this->hash($state);

        $claimed = OAuthState::query()
            ->where('state_hash', $hash)
            ->where('user_id', $user->getKey())
            ->where('platform', $platform->value)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['consumed_at' => now()]);

        if ($claimed !== 1) {
            throw SocialIntegrationException::invalidState();
        }

        return OAuthState::query()->where('state_hash', $hash)->firstOrFail();
    }

    private function hash(string $state): string
    {
        return hash('sha256', $state);
    }

    /** PKCE S256 challenge: base64url(sha256(verifier)) without padding. */
    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
