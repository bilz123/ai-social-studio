<?php

namespace App\Http\Resources;

use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A connected account as the SPA sees it. Credentials never appear here: only whether a token
 * exists, when it expires and which permissions it holds.
 */
class SocialAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SocialAccount $account */
        $account = $this->resource;

        $slug = $account->platform->slug();
        $token = $account->relationLoaded('tokens') ? $account->tokens->firstWhere('token_kind', 'access') : null;

        $required = array_values((array) config("social.providers.{$slug}.scopes", []));
        $granted = $token !== null ? array_values((array) ($token->scopes ?? [])) : [];

        return [
            'id' => $account->ulid,
            'platform' => $slug,
            'provider' => $account->provider->value,
            'name' => $account->name,
            'username' => $account->username,
            'avatar_url' => $account->avatar_url,
            'status' => $account->status->value,
            'status_reason' => $account->status_reason,
            'is_enabled' => $account->is_enabled,
            'capabilities' => $account->capabilities ?? [],
            'profile' => $account->profile ?? [],
            'permissions' => [
                'granted' => $granted,
                'missing' => array_values(array_diff($required, $granted)),
            ],
            'token' => [
                'present' => $token !== null,
                'expires_at' => $token?->expires_at?->toIso8601ZuluString(),
                'has_refresh_token' => $token !== null && $token->refresh_token !== null,
                'last_error' => $token?->last_error,
            ],
            'connected_at' => $account->created_at?->toIso8601ZuluString(),
            'last_checked_at' => $account->last_checked_at?->toIso8601ZuluString(),
            'last_publish_at' => $account->last_publish_at?->toIso8601ZuluString(),
        ];
    }
}
