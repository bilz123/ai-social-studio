<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->ulid,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'email_verified_at' => $user->email_verified_at?->toIso8601ZuluString(),
            'roles' => $user->roles()->orderBy('name')->pluck('name')->all(),
            'permissions' => $user->permissionNames(),
            'created_at' => $user->created_at?->toIso8601ZuluString(),
            'last_login_at' => $user->last_login_at?->toIso8601ZuluString(),
        ];
    }
}
