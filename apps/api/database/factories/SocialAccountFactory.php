<?php

namespace Database\Factories;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SocialAccount> */
class SocialAccountFactory extends Factory
{
    protected $model = SocialAccount::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => SocialProvider::Meta,
            'platform' => SocialPlatform::FacebookPage,
            'external_id' => (string) fake()->unique()->numerify('##########'),
            'name' => fake()->company(),
            'is_enabled' => true,
            'status' => SocialAccountStatus::Active,
        ];
    }
}
