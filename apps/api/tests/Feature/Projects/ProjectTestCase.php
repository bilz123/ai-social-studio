<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\MakesImages;
use Tests\TestCase;

/** Shared setup: a private fake disk, small limits and helpers for the project and upload APIs. */
abstract class ProjectTestCase extends TestCase
{
    use MakesImages, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('uploads.disk', 'local');
        config()->set('uploads.max_megabytes', 5);
        config()->set('uploads.max_images_per_project', 10);

        Storage::fake('local');
    }

    protected function member(): User
    {
        return User::factory()->member()->create();
    }

    protected function projectFor(User $user, string $title = 'Summer launch'): Project
    {
        return Project::factory()->for($user)->create(['title' => $title]);
    }

    protected function upload(User $user, Project $project, string $bytes, string $name = 'photo.jpg'): TestResponse
    {
        return $this->actingAs($user)->post(
            "/api/v1/projects/{$project->ulid}/images",
            ['image' => UploadedFile::fake()->createWithContent($name, $bytes)],
            ['Accept' => 'application/json'],
        );
    }

    /** Uploads a fresh, valid picture and returns the image id from the response. */
    protected function addImage(User $user, Project $project, int $seed = 1, string $format = 'jpg'): string
    {
        return (string) $this->upload($user, $project, $this->makeImage($format, 800, 600, $seed), "photo-{$seed}.{$format}")
            ->assertCreated()
            ->json('data.id');
    }

    /** @return list<string> */
    protected function storedFiles(): array
    {
        return Storage::disk('local')->allFiles();
    }

    /** @return list<string> image ids in the order the API reports them */
    protected function imageOrder(User $user, Project $project): array
    {
        return $this->actingAs($user)->getJson("/api/v1/projects/{$project->ulid}")
            ->assertOk()
            ->json('data.images.*.id');
    }
}
