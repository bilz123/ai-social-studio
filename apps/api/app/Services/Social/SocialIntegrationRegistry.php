<?php

namespace App\Services\Social;

use App\Contracts\Social\SocialIntegration;
use App\Enums\SocialPlatform;

/** Looks up the integration service for a platform. */
class SocialIntegrationRegistry
{
    /** @var array<string, SocialIntegration> */
    private array $integrations = [];

    public function __construct(FacebookService $facebook, InstagramService $instagram, YouTubeService $youtube)
    {
        foreach ([$facebook, $instagram, $youtube] as $integration) {
            $this->integrations[$integration->platform()->value] = $integration;
        }
    }

    public function for(SocialPlatform $platform): SocialIntegration
    {
        return $this->integrations[$platform->value];
    }

    /** @return list<SocialIntegration> */
    public function all(): array
    {
        return array_values($this->integrations);
    }
}
