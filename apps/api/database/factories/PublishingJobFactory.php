<?php

namespace Database\Factories;

use App\Enums\PublishingStatus;
use App\Models\GeneratedVideo;
use App\Models\PublishingJob;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PublishingJob> */
class PublishingJobFactory extends Factory
{
    protected $model = PublishingJob::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'generated_video_id' => GeneratedVideo::factory(),
            'user_id' => fn (array $attributes): int => GeneratedVideo::query()->findOrFail($attributes['generated_video_id'])->user_id,
            // A destination account owned by the same user as the video.
            'social_account_id' => fn (array $attributes): int => SocialAccount::factory()->create(['user_id' => $attributes['user_id']])->getKey(),
            'status' => PublishingStatus::Pending,
            'caption' => fake()->sentence(),
            'hashtags' => ['demo'],
        ];
    }
}
