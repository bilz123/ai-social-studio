<?php

namespace Database\Factories;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\SocialToken;
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

    public function facebook(): static
    {
        return $this->state(fn (): array => [
            'provider' => SocialProvider::Meta,
            'platform' => SocialPlatform::FacebookPage,
        ]);
    }

    public function instagram(): static
    {
        return $this->state(fn (): array => [
            'provider' => SocialProvider::Instagram,
            'platform' => SocialPlatform::InstagramBusiness,
        ]);
    }

    public function youtube(): static
    {
        return $this->state(fn (): array => [
            'provider' => SocialProvider::Google,
            'platform' => SocialPlatform::YoutubeChannel,
        ]);
    }

    public function status(SocialAccountStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /**
     * Also create the account's access token row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function withToken(array $attributes = []): static
    {
        return $this->afterCreating(function (SocialAccount $account) use ($attributes): void {
            SocialToken::factory()->create(array_merge(['social_account_id' => $account->getKey()], $attributes));
        });
    }
}
