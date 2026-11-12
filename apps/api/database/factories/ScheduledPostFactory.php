<?php

namespace Database\Factories;

use App\Enums\ScheduledPostStatus;
use App\Models\GeneratedVideo;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScheduledPost> */
class ScheduledPostFactory extends Factory
{
    protected $model = ScheduledPost::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'generated_video_id' => GeneratedVideo::factory(),
            'user_id' => fn (array $attributes): int => GeneratedVideo::query()->findOrFail($attributes['generated_video_id'])->user_id,
            'social_account_id' => fn (array $attributes): int => SocialAccount::factory()->create(['user_id' => $attributes['user_id']])->getKey(),
            'scheduled_for' => now()->addDay(),
            'timezone' => 'UTC',
            'status' => ScheduledPostStatus::Scheduled,
            'caption' => fake()->sentence(),
        ];
    }
}
