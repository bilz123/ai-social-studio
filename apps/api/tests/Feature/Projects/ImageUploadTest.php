<?php

namespace Tests\Feature\Projects;

use App\Models\ProjectImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

class ImageUploadTest extends ProjectTestCase
{
    public function test_it_stores_jpeg_png_and_webp_images_in_order(): void
    {
        $user = $this->member();
        $project = $this->projectFor($user);

        $this->upload($user, $project, $this->makeImage('jpg', 1000, 700, 1), 'a.jpg')
            ->assertCreated()
            ->assertJsonPath('data.mime', 'image/jpeg')
            ->assertJsonPath('data.width', 1000)
            ->assertJsonPath('data.height', 700)
            ->assertJsonPath('data.position', 1)
            ->assertJsonPath('data.status', 'validated');
        $this->upload($user, $project, $this->makeImage('png', 640, 640, 2, alpha: true), 'b.png')
            ->assertCreated()->assertJsonPath('data.mime', 'image/png')->assertJsonPath('data.position', 2);
        $this->upload($user, $project, $this->makeImage('webp', 900, 600, 3), 'c.webp')
            ->assertCreated()->assertJsonPath('data.mime', 'image/webp')->assertJsonPath('data.position', 3);

        $this->assertSame([1, 2, 3], $project->images()->pluck('position')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'project.image.uploaded', 'user_id' => $user->id]);
    }

    public function test_the_original_is_stored_byte_for_byte_and_never_modified(): void
    {
        $user = $this->member();
        $project = $this->projectFor($user);
        $bytes = $this->makeImage('png', 1200, 900, 4, alpha: true);

        $this->upload($user, $project, $bytes, 'logo.png')->assertCreated();

        $image = $project->images()->firstOrFail();

        $this->assertSame($bytes, Storage::disk('local')->get($image->object_key));
        $this->assertSame(hash('sha256', $bytes), $image->sha256);
        $this->assertSame(strlen($bytes), $image->bytes);
    }

    public function test_a_thumbnail_is_generated_next_to_the_original(): void
    {
        $user = $this->member();
        $project = $this->projectFor($user);

        $this->upload($user, $project, $this->makeImage('jpg', 2400, 1200, 5))->assertCreated();

        $image = $project->images()->firstOrFail();
        $thumbnail = Storage::disk('local')->get($image->thumbnail_key);
        [$width, $height, $type] = getimagesizefromstring($thumbnail);

        $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertSame(640, $width);
        $this->assertSame(320, $height);
        $this->assertSame(strlen($thumbnail), $image->thumbnail_bytes);
        $this->assertNotSame($image->object_key, $image->thumbnail_key);
        // The original keeps its full size.
        $this->assertSame([2400, 1200], [$image->width, $image->height]);
    }

    public function test_a_small_image_is_not_enlarged_in_its_thumbnail(): void
    {
        $user = $this->member();
        $project = $this->projectFor($user);

        $this->upload($user, $project, $this->makeImage('jpg', 400, 300, 6))->assertCreated();

        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($project->images()->firstOrFail()->thumbnail_key));
        $this->assertSame([400, 300], [$width, $height]);
    }

    public function test_file_names_are_generated_and_never_taken_from_the_client(): void
    {
        $user = $this->member();
        $project = $this->projectFor($user);

        // A JPEG pretending to be a PNG with a hostile name: the extension comes from the content.
        $response = $this->upload($user, $project, $this->makeImage('jpg', 800, 600, 7), '../../public/evil.php.png')
            ->assertCreated();

        $image = $project->images()->firstOrFail();

        $this->assertMatchesRegularExpression(
            '#^users/'.$user->ulid.'/projects/'.$project->ulid.'/original/[0-9A-HJKMNP-TV-Z]{26}\.jpg$#',
            $image->object_key,
        );
        $this->assertStringNotContainsString('evil', $image->object_key);
        $this->assertStringNotContainsString('evil', (string) $image->thumbnail_key);
        $this->assertSame('evil.php.png', $response->json('data.name')); // shown only, never used as a path
        $this->assertTrue(Storage::disk('local')->exists($image->object_key));
    }

    public function test_every_upload_gets_its_own_unique_key(): void
    {
        $user = $this->member();
        $project = $this->projectFor($user);

        $this->addImage($user, $project, 1);
        $this->addImage($user, $project, 2);

        $keys = $project->images()->pluck('object_key')->all();
        $this->assertCount(2, array_unique($keys));
    }

    public function test_the_response_never_reveals_storage_details(): void
    {
        $user = $this->member();
        $project = $this->projectFor($user);

        $json = $this->upload($user, $project, $this->makeImage('jpg', 800, 600, 8))->assertCreated()->getContent();

        $this->assertStringNotContainsString('object_key', $json);
        $this->assertStringNotContainsString('thumbnail_key', $json);
        $this->assertStringNotContainsString('"disk"', $json);
        $this->assertStringNotContainsString('users\\/', $json);
        $this->assertStringNotContainsString('sha256', $json);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function rejectedFiles(): array
    {
        return [
            'plain text with an image name' => ['text', 'image_unsupported_type'],
            'gif' => ['gif', 'image_unsupported_type'],
            'svg with a script' => ['svg', 'image_unsupported_type'],
            'php code behind a gif header' => ['polyglot', 'image_unsupported_type'],
            'pdf' => ['pdf', 'image_unsupported_type'],
            'cut-off jpeg' => ['truncated_jpeg', 'image_corrupt'],
            'cut-off png' => ['truncated_png', 'image_corrupt'],
            'too small' => ['tiny', 'image_too_small'],
            'absurd pixel count' => ['bomb', 'image_dimensions_too_large'],
            'empty file' => ['empty', 'image_empty'],
        ];
    }

    #[DataProvider('rejectedFiles')]
    public function test_it_rejects_files_that_are_not_valid_jpeg_png_or_webp_images(string $kind, string $code): void
    {
        $user = $this->member();
        $project = $this->projectFor($user);

        $bytes = match ($kind) {
            'text' => str_repeat("This is not a picture.\n", 80),
            'gif' => $this->makeImage('gif', 500, 500),
            'svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="500" height="500"><script>alert(1)</script></svg>',
            'polyglot' => "GIF89a<?php system(\$_GET['c']); ?>".str_repeat('A', 400),
            'pdf' => "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF",
            'truncated_jpeg' => $this->truncated($this->makeImage('jpg', 1200, 800, 10), 0.5),
            'truncated_png' => $this->truncated($this->makeImage('png', 1200, 800, 11), 0.5),
            'tiny' => $this->makeImage('jpg', 100, 100, 12),
            'bomb' => $this->pngClaimingSize(30000, 30000),
            'empty' => '',
        };

        // Always uploaded under an innocent image name and a JPEG claim.
        $this->upload($user, $project, $bytes, 'holiday.jpg')
            ->assertStatus(422)
            ->assertJsonPath('code', $code);

        // Nothing is kept: no row, no file.
        $this->assertDatabaseCount('project_images', 0);
        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_file_over_the_size_limit_is_rejected(): void
    {
        config()->set('uploads.max_megabytes', 1);

        $user = $this->member();
        $project = $this->projectFor($user);

        $response = $this->actingAs($user)->post(
            "/api/v1/projects/{$project->ulid}/images",
            ['image' => UploadedFile::fake()->createWithContent('big.jpg', $this->makeImage('jpg', 800, 600, 13).str_repeat("\0", 1_200_000))],
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(422)->assertJsonValidationErrors(['image'], 'errors');
        $this->assertStringContainsString('1 MB', $response->json('errors.image.0'));
        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_request_without_a_file_is_rejected(): void
    {
        $user = $this->member();
        $project = $this->projectFor($user);

        $this->actingAs($user)->postJson("/api/v1/projects/{$project->ulid}/images", [])
            ->assertStatus(422)->assertJsonValidationErrors(['image'], 'errors');
        $this->actingAs($user)->postJson("/api/v1/projects/{$project->ulid}/images", ['image' => 'not-a-file'])
            ->assertStatus(422)->assertJsonValidationErrors(['image'], 'errors');
    }

    public function test_a_project_cannot_hold_more_than_the_limit(): void
    {
        config()->set('uploads.max_images_per_project', 2);

        $user = $this->member();
        $project = $this->projectFor($user);

        $this->addImage($user, $project, 1);
        $this->addImage($user, $project, 2);

        $this->upload($user, $project, $this->makeImage('jpg', 800, 600, 3))
            ->assertStatus(422)->assertJsonPath('code', 'image_limit_reached');

        $this->assertDatabaseCount('project_images', 2);
        $this->assertCount(4, $this->storedFiles());
    }

    public function test_the_same_picture_cannot_be_added_twice_and_leaves_no_stray_files(): void
    {
        $user = $this->member();
        $project = $this->projectFor($user);
        $bytes = $this->makeImage('jpg', 800, 600, 14);

        $this->upload($user, $project, $bytes, 'one.jpg')->assertCreated();
        $this->upload($user, $project, $bytes, 'two.jpg')->assertStatus(422)->assertJsonPath('code', 'image_duplicate');

        $this->assertDatabaseCount('project_images', 1);
        $this->assertCount(2, $this->storedFiles()); // the first upload's original and thumbnail only

        // The same picture is fine in a different project.
        $this->upload($user, $this->projectFor($user, 'Second'), $bytes, 'one.jpg')->assertCreated();
    }

    public function test_an_upload_to_someone_elses_project_stores_nothing(): void
    {
        $owner = $this->member();
        $intruder = $this->member();
        $project = $this->projectFor($owner);

        $this->upload($intruder, $project, $this->makeImage('jpg', 800, 600, 15))->assertNotFound();

        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, ProjectImage::query()->count());
    }

    public function test_guests_cannot_upload(): void
    {
        $project = $this->projectFor($this->member());

        $this->post("/api/v1/projects/{$project->ulid}/images", [
            'image' => UploadedFile::fake()->createWithContent('a.jpg', $this->makeImage('jpg', 800, 600, 16)),
        ], ['Accept' => 'application/json'])->assertUnauthorized();

        $this->assertSame([], $this->storedFiles());
    }

    public function test_the_stored_dimensions_follow_the_exif_orientation(): void
    {
        $user = $this->member();
        $project = $this->projectFor($user);

        // 1200x800 pixels flagged "rotate 90° clockwise": people see 800 wide, 1200 tall.
        $this->upload($user, $project, $this->makeJpegWithOrientation(6), 'portrait.jpg')
            ->assertCreated()
            ->assertJsonPath('data.width', 800)
            ->assertJsonPath('data.height', 1200);

        [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($project->images()->firstOrFail()->thumbnail_key));
        $this->assertSame([427, 640], [$width, $height]);
    }
}
