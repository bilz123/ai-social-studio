<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Compact user row for administrators. Callers must eager load `roles`. */
class AdminUserResource extends JsonResource
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
            'roles' => $user->roles->pluck('name')->sort()->values()->all(),
            'created_at' => $user->created_at?->toIso8601ZuluString(),
            'last_login_at' => $user->last_login_at?->toIso8601ZuluString(),
        ];
    }
}
