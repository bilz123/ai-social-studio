<?php

namespace App\Services\Social\Data;

use Carbon\CarbonInterface;

/** Credentials returned by a provider. Never log or serialize this object. */
final readonly class TokenSet
{
    /**
     * @param  list<string>  $scopes  permissions the user actually granted
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
        public ?CarbonInterface $expiresAt = null,
        public array $scopes = [],
        public string $tokenType = 'bearer',
        public ?string $providerUserId = null,
    ) {}
}
