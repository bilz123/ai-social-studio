<?php

namespace Database\Factories;

use App\Enums\ImageStatus;
use App\Models\Project;
use App\Models\ProjectImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProjectImage> */
class ProjectImageFactory extends Factory
{
    protected $model = ProjectImage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            // Always the owner of the project, also when the project is passed with ->for().
            'user_id' => fn (array $attributes): int => Project::query()->findOrFail($attributes['project_id'])->user_id,
            'disk' => config('uploads.disk'),
            'object_key' => 'uploads/'.Str::ulid().'.jpg',
            'thumbnail_key' => null,
            'thumbnail_bytes' => null,
            'original_name' => fake()->word().'.jpg',
            'mime' => 'image/jpeg',
            'bytes' => fake()->numberBetween(100_000, 5_000_000),
            'width' => 2000,
            'height' => 1500,
            'position' => fake()->unique()->numberBetween(1, 60000),
            'role' => 'other',
            'status' => ImageStatus::Validated,
        ];
    }
}
