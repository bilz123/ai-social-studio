<?php

namespace Database\Factories;

use App\Enums\PublishingStatus;
use App\Models\PublishedPost;
use App\Models\PublishingJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PublishedPost> */
class PublishedPostFactory extends Factory
{
    protected $model = PublishedPost::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'publishing_job_id' => PublishingJob::factory()->state(['status' => PublishingStatus::Published]),
            'user_id' => fn (array $attributes): int => PublishingJob::query()->findOrFail($attributes['publishing_job_id'])->user_id,
            'social_account_id' => fn (array $attributes): int => PublishingJob::query()->findOrFail($attributes['publishing_job_id'])->social_account_id,
            'external_post_id' => (string) fake()->unique()->numerify('################'),
            'external_url' => 'https://example.com/post/'.fake()->uuid(),
            'published_at' => now(),
        ];
    }
}
