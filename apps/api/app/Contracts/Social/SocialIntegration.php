<?php

namespace App\Contracts\Social;

use App\Enums\SocialPlatform;
use App\Exceptions\SocialIntegrationException;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Services\Social\Data\AccountSnapshot;
use App\Services\Social\Data\DiscoveredAccount;
use App\Services\Social\Data\TokenSet;

/**
 * What every social platform integration provides. Controllers and jobs depend on this interface
 * only; each platform (Facebook, Instagram, YouTube) implements the provider-specific details.
 *
 * All methods throw SocialIntegrationException with a user-safe message on failure.
 */
interface SocialIntegration
{
    public function platform(): SocialPlatform;

    public function label(): string;

    /** True when the developer credentials needed for OAuth are present in the environment. */
    public function isConfigured(): bool;

    /** @return list<string> names of empty environment variables that block this integration */
    public function missingConfiguration(): array;

    /** @return list<string> permissions the app needs to publish video */
    public function requiredScopes(): array;

    /** Whether the OAuth flow uses PKCE (a code verifier is generated and sent on exchange). */
    public function usesPkce(): bool;

    /** Whether stored credentials can be renewed without the user (refresh token / token exchange). */
    public function supportsRefresh(): bool;

    /** The provider consent-screen URL for this state (and PKCE challenge, when used). */
    public function authorizationUrl(string $state, ?string $codeChallenge = null): string;

    /**
     * Exchange the authorization code and discover the destinations the user shared.
     *
     * @return list<DiscoveredAccount>
     *
     * @throws SocialIntegrationException
     */
    public function completeAuthorization(string $code, ?string $codeVerifier = null): array;

    /**
     * Renew credentials. Returns the new token set; fields the provider omits (such as the refresh
     * token) are kept by the caller.
     *
     * @throws SocialIntegrationException
     */
    public function refresh(SocialAccount $account, SocialToken $token): TokenSet;

    /**
     * Ask the provider whether the credentials still work and what the account looks like now.
     *
     * @throws SocialIntegrationException
     */
    public function inspect(SocialAccount $account, SocialToken $token): AccountSnapshot;

    /** Revoke the grant at the provider where the API supports it. Best effort; never throws. */
    public function revoke(SocialAccount $account, SocialToken $token): void;
}
