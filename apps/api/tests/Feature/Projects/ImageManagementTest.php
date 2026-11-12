<?php

namespace Tests\Feature\Projects;

use App\Models\ProjectImage;
use Illuminate\Support\Facades\Storage;

class ImageManagementTest extends ProjectTestCase
{
    public function test_deleting_an_image_removes_its_row_and_both_files_and_closes_the_gap(): void
    {
        $user = $this->member();
        $project = $this->projectFor($user);

        $first = $this->addImage($user, $project, 1);
        $second = $this->addImage($user, $project, 2);
        $third = $this->addImage($user, $project, 3);

        $removed = ProjectImage::query()->where('ulid', $second)->firstOrFail();
        $keys = [$removed->object_key, $removed->thumbnail_key];

        $this->actingAs($user)->deleteJson("/api/v1/projects/{$project->ulid}/images/{$second}")->assertNoContent();

        $this->assertDatabaseMissing('project_images', ['ulid' => $second]);
        foreach ($keys as $key) {
            Storage::disk('local')->assertMissing($key);
        }
        $this->assertCount(4, $this->storedFiles());

        // The remaining images keep their order and are renumbered 1..n.
        $this->assertSame([$first, $third], $this->imageOrder($user, $project));
        $this->assertSame([1, 2], $project->images()->pluck('position')->all());

        $this->assertDatabaseHas('audit_logs', ['action' => 'project.image.deleted', 'user_id' => $user->id]);

        // A new upload continues after the last position.
        $fourth = $this->addImage($user, $project, 4);
        $this->assertSame([$first, $third, $fourth], $this->imageOrder($user, $project));
    }

    public function test_deleting_an_unknown_image_or_one_from_another_project_is_not_found(): void
    {
        $user = $this->member();
        $projectA = $this->projectFor($user, 'A');
        $projectB = $this->projectFor($user, 'B');
        $inB = $this->addImage($user, $projectB, 1);

        $this->actingAs($user)->deleteJson("/api/v1/projects/{$projectA->ulid}/images/{$inB}")->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/v1/projects/{$projectA->ulid}/images/01ARZ3NDEKTSV4RRFFQ69G5FAV")->assertNotFound();

        $this->assertDatabaseHas('project_images', ['ulid' => $inB]);
    }

    public function test_images_can_be_reordered(): void
    {
        $user = $this->member();
        $project = $this->projectFor($user);

        $a = $this->addImage($user, $project, 1);
        $b = $this->addImage($user, $project, 2);
        $c = $this->addImage($user, $project, 3);

        $response = $this->actingAs($user)->putJson("/api/v1/projects/{$project->ulid}/images/order", ['order' => [$c, $a, $b]])
            ->assertOk();

        $this->assertSame([$c, $a, $b], $response->json('data.*.id'));
        $this->assertSame([1, 2, 3], $response->json('data.*.position'));
        $this->assertSame([$c, $a, $b], $this->imageOrder($user, $project));

        // The first image is the project's cover in lists.
        $this->actingAs($user)->getJson('/api/v1/projects')->assertOk()->assertJsonPath('data.0.id', $project->ulid);
        $this->assertDatabaseHas('audit_logs', ['action' => 'project.images.reordered', 'user_id' => $user->id]);

        // Reversing again, and repeating the same order, both work (positions never collide).
        $this->actingAs($user)->putJson("/api/v1/projects/{$project->ulid}/images/order", ['order' => [$b, $a, $c]])->assertOk();
        $this->actingAs($user)->putJson("/api/v1/projects/{$project->ulid}/images/order", ['order' => [$b, $a, $c]])->assertOk();
        $this->assertSame([$b, $a, $c], $this->imageOrder($user, $project));
        $this->assertSame([1, 2, 3], $project->images()->pluck('position')->all());
    }

    public function test_a_reorder_must_list_every_image_exactly_once(): void
    {
        $user = $this->member();
        $project = $this->projectFor($user);
        $a = $this->addImage($user, $project, 1);
        $b = $this->addImage($user, $project, 2);

        $stranger = $this->member();
        $foreignProject = $this->projectFor($stranger, 'Foreign');
        $foreignImage = $this->addImage($stranger, $foreignProject, 3);

        $url = "/api/v1/projects/{$project->ulid}/images/order";

        $this->actingAs($user)->putJson($url, ['order' => [$a]])->assertStatus(422)->assertJsonValidationErrors(['order'], 'errors');
        $this->actingAs($user)->putJson($url, ['order' => [$a, $a]])->assertStatus(422);
        $this->actingAs($user)->putJson($url, ['order' => [$a, $foreignImage]])->assertStatus(422)->assertJsonValidationErrors(['order'], 'errors');
        $this->actingAs($user)->putJson($url, ['order' => [$a, $b, $foreignImage]])->assertStatus(422)->assertJsonValidationErrors(['order'], 'errors');
        $this->actingAs($user)->putJson($url, ['order' => []])->assertStatus(422);
        $this->actingAs($user)->putJson($url, [])->assertStatus(422);
        $this->actingAs($user)->putJson($url, ['order' => [$a, 'short']])->assertStatus(422);

        // Nothing changed, and the other user's image was not touched.
        $this->assertSame([$a, $b], $this->imageOrder($user, $project));
        $this->assertSame(1, ProjectImage::query()->where('ulid', $foreignImage)->value('position'));
    }

    public function test_only_the_owner_sees_and_changes_the_order(): void
    {
        $owner = $this->member();
        $project = $this->projectFor($owner);
        $a = $this->addImage($owner, $project, 1);
        $b = $this->addImage($owner, $project, 2);

        $this->actingAs($this->member())->putJson("/api/v1/projects/{$project->ulid}/images/order", ['order' => [$b, $a]])->assertNotFound();
        $this->assertSame([$a, $b], $this->imageOrder($owner, $project));
    }
}
