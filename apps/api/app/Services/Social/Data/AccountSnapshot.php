<?php

namespace App\Services\Social\Data;

use Carbon\CarbonInterface;

/** What a provider currently says about one connected destination (page, profile or channel). */
final readonly class AccountSnapshot
{
    /**
     * @param  array<string, bool|int|string|null>  $capabilities
     * @param  array<string, bool|int|string|null>  $profile
     * @param  list<string>|null  $grantedScopes  null when the provider cannot report them
     */
    public function __construct(
        public string $externalId,
        public string $name,
        public ?string $username = null,
        public ?string $avatarUrl = null,
        public array $capabilities = [],
        public array $profile = [],
        public ?array $grantedScopes = null,
        public ?CarbonInterface $tokenExpiresAt = null,
    ) {}
}
