<?php

namespace App\Http\Controllers\Api\V1\Social;

use App\Http\Controllers\Controller;
use App\Services\Social\SocialIntegrationRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SocialPlatformController extends Controller
{
    /**
     * Which platforms can be connected on this server. `configured` is false until an
     * administrator adds the platform's developer credentials; the UI then explains this instead
     * of offering a button that cannot work.
     */
    public function index(Request $request, SocialIntegrationRegistry $integrations): JsonResponse
    {
        $counts = $request->user()->socialAccounts()
            ->selectRaw('platform, count(*) as total')
            ->groupBy('platform')
            ->pluck('total', 'platform');

        $platforms = array_map(function ($integration) use ($counts): array {
            $platform = $integration->platform();

            return array_filter([
                'id' => $platform->slug(),
                'name' => $integration->label(),
                'provider' => $platform->provider()->value,
                'configured' => $integration->isConfigured(),
                'supports_refresh' => $integration->supportsRefresh(),
                'required_permissions' => $integration->requiredScopes(),
                'connected_count' => (int) ($counts[$platform->value] ?? 0),
                // Names of missing environment variables help whoever sets the server up, but are
                // not shown to end users in production.
                'missing_configuration' => app()->isProduction() ? null : $integration->missingConfiguration(),
            ], fn ($value): bool => $value !== null);
        }, $integrations->all());

        return response()->json(['data' => $platforms]);
    }
}
