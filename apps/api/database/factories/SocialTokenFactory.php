<?php

namespace Database\Factories;

use App\Models\SocialAccount;
use App\Models\SocialToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SocialToken> */
class SocialTokenFactory extends Factory
{
    protected $model = SocialToken::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'social_account_id' => SocialAccount::factory(),
            'token_kind' => 'access',
            'access_token' => 'access-'.Str::random(40),
            'refresh_token' => 'refresh-'.Str::random(40),
            'token_type' => 'bearer',
            'expires_at' => now()->addDays(60),
            'scopes' => ['pages_manage_posts'],
        ];
    }
}
