<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Project;
use App\Models\VideoGenerationJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VideoGenerationJob> */
class VideoGenerationJobFactory extends Factory
{
    protected $model = VideoGenerationJob::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => fn (array $attributes): int => Project::query()->findOrFail($attributes['project_id'])->user_id,
            'version' => fake()->unique()->numberBetween(1, 60000),
            'status' => GenerationStatus::Queued,
            'style_preset' => 'cinematic_dolly',
            'duration_ms' => 10000,
            'aspect_ratio' => '9:16',
            'progress' => 0,
        ];
    }
}
