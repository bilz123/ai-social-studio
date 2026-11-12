<?php

namespace Tests\Feature\Projects;

use App\Models\ProjectImage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class ImageFileAccessTest extends ProjectTestCase
{
    /** @return array{0: string, 1: string, 2: string, 3: string} thumbnail url, original url, original bytes, image id */
    private function uploadedImage(): array
    {
        $user = $this->member();
        $project = $this->projectFor($user);
        $bytes = $this->makeImage('png', 1500, 1000, 21);

        $this->upload($user, $project, $bytes, 'product.png')->assertCreated();

        $data = $this->actingAs($user)->getJson("/api/v1/projects/{$project->ulid}")->assertOk()->json('data.images.0');

        return [$data['thumbnail_url'], $data['original_url'], $bytes, $data['id']];
    }

    public function test_a_signed_thumbnail_link_serves_the_preview_without_a_session(): void
    {
        [$thumbnailUrl, , , $id] = $this->uploadedImage();

        // No actingAs: the link is the credential.
        $response = $this->get($thumbnailUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");

        $thumbnail = Storage::disk('local')->get(ProjectImage::query()->where('ulid', $id)->firstOrFail()->thumbnail_key);
        $this->assertSame($thumbnail, $response->streamedContent());
        $this->assertSame(strlen($thumbnail), (int) $response->headers->get('Content-Length'));
    }

    public function test_a_signed_original_link_serves_the_untouched_original(): void
    {
        [, $originalUrl, $bytes] = $this->uploadedImage();

        $response = $this->get($originalUrl)->assertOk()->assertHeader('Content-Type', 'image/png');

        $this->assertSame($bytes, $response->streamedContent());
    }

    public function test_links_are_relative_and_reveal_neither_paths_nor_names(): void
    {
        [$thumbnailUrl, $originalUrl] = $this->uploadedImage();

        foreach ([$thumbnailUrl, $originalUrl] as $url) {
            $this->assertStringStartsWith('/api/v1/files/images/', $url);
            $this->assertStringContainsString('signature=', $url);
            $this->assertStringContainsString('expires=', $url);
            $this->assertStringNotContainsString('users/', $url);
            $this->assertStringNotContainsString('product', $url);
        }
    }

    public function test_links_without_a_valid_signature_are_refused(): void
    {
        [$thumbnailUrl, $originalUrl] = $this->uploadedImage();

        // Unsigned.
        $this->get(strtok($thumbnailUrl, '?'))->assertForbidden();
        // Tampered signature.
        $this->get((string) preg_replace('/signature=[0-9a-f]+/', 'signature=deadbeef', $thumbnailUrl))->assertForbidden();
        // Swapping the thumbnail link for the original (the signature covers the whole path).
        $this->get(str_replace('/thumb?', '/original?', $thumbnailUrl))->assertForbidden();
        // Extending the expiry.
        $this->get((string) preg_replace('/expires=\d+/', 'expires=9999999999', $originalUrl))->assertForbidden();
    }

    public function test_links_expire(): void
    {
        [$thumbnailUrl] = $this->uploadedImage();

        $this->get($thumbnailUrl)->assertOk();

        $this->travel(20)->minutes();

        $this->get($thumbnailUrl)->assertForbidden();
    }

    public function test_a_link_stops_working_once_the_image_is_deleted(): void
    {
        $user = $this->member();
        $project = $this->projectFor($user);
        $id = $this->addImage($user, $project, 31);

        $url = $this->actingAs($user)->getJson("/api/v1/projects/{$project->ulid}")->json('data.images.0.thumbnail_url');
        $this->get($url)->assertOk();

        $this->actingAs($user)->deleteJson("/api/v1/projects/{$project->ulid}/images/{$id}")->assertNoContent();

        $this->get($url)->assertNotFound();
    }

    public function test_a_missing_file_is_a_404_not_an_error(): void
    {
        [$thumbnailUrl, , , $id] = $this->uploadedImage();

        Storage::disk('local')->delete(ProjectImage::query()->where('ulid', $id)->firstOrFail()->thumbnail_key);

        $this->get($thumbnailUrl)->assertNotFound();
    }

    public function test_an_unknown_image_is_not_found_even_with_a_valid_signature(): void
    {
        $url = URL::temporarySignedRoute('v1.files.images.show', now()->addMinutes(5), [
            'image' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'variant' => 'thumb',
        ], absolute: false);

        $this->get($url)->assertNotFound();
    }

    public function test_only_known_variants_are_routable(): void
    {
        $user = $this->member();
        $project = $this->projectFor($user);
        $id = $this->addImage($user, $project, 41);

        $url = URL::temporarySignedRoute('v1.files.images.show', now()->addMinutes(5), ['image' => $id, 'variant' => 'secret'], absolute: false);

        $this->get($url)->assertNotFound();
    }

    public function test_storage_paths_cannot_be_reached_directly(): void
    {
        $user = $this->member();
        $project = $this->projectFor($user);
        $this->addImage($user, $project, 51);

        $key = $project->images()->firstOrFail()->object_key;

        foreach (["/storage/{$key}", "/{$key}", "/api/v1/{$key}"] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_the_same_link_is_returned_within_a_time_bucket_and_a_new_one_after(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:30');

        $user = $this->member();
        $project = $this->projectFor($user);
        $this->addImage($user, $project, 61);

        $url = fn (): string => (string) $this->actingAs($user)->getJson("/api/v1/projects/{$project->ulid}")->json('data.images.0.thumbnail_url');

        $first = $url();
        $this->assertSame($first, $url());

        Carbon::setTestNow('2026-11-12 10:06:00');
        $this->assertNotSame($first, $url());

        Carbon::setTestNow();
    }

    public function test_links_stay_valid_for_at_least_the_configured_time(): void
    {
        Carbon::setTestNow('2026-11-12 10:04:59');

        $user = $this->member();
        $project = $this->projectFor($user);
        $this->addImage($user, $project, 71);
        $url = (string) $this->actingAs($user)->getJson("/api/v1/projects/{$project->ulid}")->json('data.images.0.thumbnail_url');

        Carbon::setTestNow('2026-11-12 10:14:00'); // 9 minutes later
        $this->get($url)->assertOk();

        Carbon::setTestNow('2026-11-12 10:16:00'); // past the bucket plus the lifetime
        $this->get($url)->assertForbidden();

        Carbon::setTestNow();
    }
}
