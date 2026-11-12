<?php

namespace Database\Factories;

use App\Enums\ApprovalStatus;
use App\Models\GeneratedVideo;
use App\Models\VideoGenerationJob;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<GeneratedVideo> */
class GeneratedVideoFactory extends Factory
{
    protected $model = GeneratedVideo::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'generation_job_id' => VideoGenerationJob::factory(),
            'user_id' => fn (array $attributes): int => VideoGenerationJob::query()->findOrFail($attributes['generation_job_id'])->user_id,
            'disk' => 's3',
            'object_key' => 'final/'.Str::ulid().'/master.mp4',
            'thumbnail_key' => 'final/'.Str::ulid().'/thumb.jpg',
            'aspect_ratio' => '9:16',
            'width' => 1080,
            'height' => 1920,
            'duration_ms' => 10000,
            'fps' => 30,
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
            'has_audio' => true,
            'bytes' => fake()->numberBetween(1_000_000, 20_000_000),
            'is_current' => true,
            'approval_status' => ApprovalStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'approval_status' => ApprovalStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }
}
