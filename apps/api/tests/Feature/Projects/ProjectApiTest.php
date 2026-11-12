<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class ProjectApiTest extends ProjectTestCase
{
    public function test_guests_are_rejected_and_users_without_the_permission_are_forbidden(): void
    {
        $this->getJson('/api/v1/projects')->assertUnauthorized();
        $this->postJson('/api/v1/projects', ['title' => 'x'])->assertUnauthorized();

        // A user without the member role has no projects.manage permission.
        $this->actingAs(User::factory()->create())->getJson('/api/v1/projects')->assertForbidden();
    }

    public function test_creating_a_project_makes_a_draft_owned_by_the_caller(): void
    {
        $user = $this->member();

        $response = $this->actingAs($user)->postJson('/api/v1/projects', ['title' => '  Summer launch  '])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Summer launch')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.product_description', '')
            ->assertJsonPath('data.image_count', 0);

        // The product name follows the title until it is set explicitly.
        $response->assertJsonPath('data.product_name', 'Summer launch');

        $this->assertDatabaseHas('projects', ['ulid' => $response->json('data.id'), 'user_id' => $user->id, 'status' => 'draft']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'project.created', 'user_id' => $user->id]);
    }

    public function test_a_draft_can_be_created_with_a_description_and_validates_input(): void
    {
        $user = $this->member();

        $this->actingAs($user)->postJson('/api/v1/projects', ['title' => 'Mug', 'product_description' => 'A handmade ceramic mug.'])
            ->assertCreated()
            ->assertJsonPath('data.product_description', 'A handmade ceramic mug.');

        $this->actingAs($user)->postJson('/api/v1/projects', [])->assertStatus(422)->assertJsonValidationErrors(['title'], 'errors');
        $this->actingAs($user)->postJson('/api/v1/projects', ['title' => '   '])->assertStatus(422)->assertJsonValidationErrors(['title'], 'errors');
        $this->actingAs($user)->postJson('/api/v1/projects', ['title' => str_repeat('a', 161)])->assertStatus(422)->assertJsonValidationErrors(['title'], 'errors');
        $this->actingAs($user)->postJson('/api/v1/projects', ['title' => 'Ok', 'product_description' => str_repeat('a', 2001)])
            ->assertStatus(422)->assertJsonValidationErrors(['product_description'], 'errors');
    }

    public function test_request_input_cannot_set_the_owner_or_status(): void
    {
        $user = $this->member();
        $other = $this->member();

        $id = $this->actingAs($user)->postJson('/api/v1/projects', [
            'title' => 'Mine',
            'user_id' => $other->id,
            'status' => 'ready',
            'ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ])->assertCreated()->json('data.id');

        $this->assertNotSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $id);
        $this->assertDatabaseHas('projects', ['ulid' => $id, 'user_id' => $user->id, 'status' => 'draft']);

        $this->actingAs($user)->patchJson("/api/v1/projects/{$id}", ['title' => 'Still mine', 'user_id' => $other->id, 'status' => 'archived'])
            ->assertOk();
        $this->assertDatabaseHas('projects', ['ulid' => $id, 'user_id' => $user->id, 'status' => 'draft', 'title' => 'Still mine']);
    }

    public function test_the_list_only_contains_the_callers_projects_with_cover_and_count(): void
    {
        $user = $this->member();
        $other = $this->member();

        $mine = $this->projectFor($user, 'Mine');
        $this->projectFor($other, 'Not mine');
        $empty = $this->projectFor($user, 'Empty');

        $this->addImage($user, $mine, 1);
        $this->addImage($user, $mine, 2);

        $response = $this->actingAs($user)->getJson('/api/v1/projects')->assertOk();

        $this->assertEqualsCanonicalizing(['Mine', 'Empty'], $response->json('data.*.title'));

        $byTitle = collect($response->json('data'))->keyBy('title');
        $this->assertSame(2, $byTitle['Mine']['image_count']);
        $this->assertNotNull($byTitle['Mine']['cover_thumbnail_url']);
        $this->assertSame(0, $byTitle['Empty']['image_count']);
        $this->assertNull($byTitle['Empty']['cover_thumbnail_url']);
        $this->assertSame($empty->ulid, $byTitle['Empty']['id']);
    }

    public function test_showing_a_project_returns_images_in_order_with_limits(): void
    {
        $user = $this->member();
        $project = $this->projectFor($user);
        $first = $this->addImage($user, $project, 1);
        $second = $this->addImage($user, $project, 2);

        $response = $this->actingAs($user)->getJson("/api/v1/projects/{$project->ulid}")
            ->assertOk()
            ->assertJsonPath('data.id', $project->ulid)
            ->assertJsonPath('data.image_count', 2)
            ->assertJsonPath('meta.limits.max_images', 10)
            ->assertJsonPath('meta.limits.max_bytes', 5 * 1024 * 1024);

        $this->assertSame([$first, $second], $response->json('data.images.*.id'));
        $this->assertSame([1, 2], $response->json('data.images.*.position'));
        $this->assertContains('image/webp', $response->json('meta.limits.formats'));
    }

    public function test_saving_a_draft_updates_only_the_sent_fields(): void
    {
        $user = $this->member();
        $project = $this->projectFor($user, 'Old name');
        $project->forceFill(['product_description' => 'Old description'])->save();

        $this->actingAs($user)->patchJson("/api/v1/projects/{$project->ulid}", ['title' => 'New name'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New name')
            ->assertJsonPath('data.product_description', 'Old description');

        $this->actingAs($user)->patchJson("/api/v1/projects/{$project->ulid}", ['product_description' => 'A longer description of the product.'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New name')
            ->assertJsonPath('data.product_description', 'A longer description of the product.');

        // Clearing the description is allowed for a draft.
        $this->actingAs($user)->patchJson("/api/v1/projects/{$project->ulid}", ['product_description' => null])
            ->assertOk()
            ->assertJsonPath('data.product_description', '');

        // The name cannot be blanked out.
        $this->actingAs($user)->patchJson("/api/v1/projects/{$project->ulid}", ['title' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['title'], 'errors');

        $this->assertDatabaseHas('audit_logs', ['action' => 'project.updated', 'user_id' => $user->id]);
    }

    public function test_the_product_name_follows_the_title_until_it_is_set_on_its_own(): void
    {
        $user = $this->member();
        $id = $this->actingAs($user)->postJson('/api/v1/projects', ['title' => 'Untitled project'])->assertCreated()->json('data.id');

        $this->actingAs($user)->patchJson("/api/v1/projects/{$id}", ['title' => 'Cold brew'])
            ->assertOk()->assertJsonPath('data.product_name', 'Cold brew');

        $this->actingAs($user)->patchJson("/api/v1/projects/{$id}", ['product_name' => 'Arctic Cold Brew'])
            ->assertOk()->assertJsonPath('data.product_name', 'Arctic Cold Brew');

        // Once set separately, renaming the project leaves it alone.
        $this->actingAs($user)->patchJson("/api/v1/projects/{$id}", ['title' => 'Winter campaign'])
            ->assertOk()->assertJsonPath('data.product_name', 'Arctic Cold Brew');
    }

    public function test_another_users_project_is_not_found_for_every_operation(): void
    {
        $owner = $this->member();
        $intruder = $this->member();
        $project = $this->projectFor($owner);
        $imageId = $this->addImage($owner, $project);

        $base = "/api/v1/projects/{$project->ulid}";

        $this->actingAs($intruder)->getJson($base)->assertNotFound();
        $this->actingAs($intruder)->patchJson($base, ['title' => 'Hijacked'])->assertNotFound();
        $this->actingAs($intruder)->deleteJson($base)->assertNotFound();
        $this->actingAs($intruder)->putJson("{$base}/images/order", ['order' => [$imageId]])->assertNotFound();
        $this->actingAs($intruder)->deleteJson("{$base}/images/{$imageId}")->assertNotFound();
        $this->upload($intruder, $project, $this->makeImage('jpg', 800, 600, 9))->assertNotFound();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'title' => 'Summer launch', 'user_id' => $owner->id]);
        $this->assertDatabaseCount('project_images', 1);
        $this->assertCount(2, $this->storedFiles()); // the owner's original + thumbnail, nothing else
    }

    public function test_an_unknown_project_is_not_found(): void
    {
        $this->actingAs($this->member())->getJson('/api/v1/projects/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
    }

    public function test_deleting_a_project_removes_its_images_and_files(): void
    {
        $user = $this->member();
        $other = $this->member();

        $project = $this->projectFor($user);
        $this->addImage($user, $project, 1);
        $this->addImage($user, $project, 2);

        $otherProject = $this->projectFor($other, 'Other');
        $this->addImage($other, $otherProject, 3);

        $this->assertCount(6, $this->storedFiles());

        $this->actingAs($user)->deleteJson("/api/v1/projects/{$project->ulid}")->assertNoContent();

        $this->actingAs($user)->getJson("/api/v1/projects/{$project->ulid}")->assertNotFound();
        $this->assertSoftDeleted('projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('project_images', ['project_id' => $project->id]);

        // Only the other user's files remain.
        $remaining = $this->storedFiles();
        $this->assertCount(2, $remaining);
        foreach ($remaining as $path) {
            $this->assertStringStartsWith('users/'.$other->ulid.'/', $path);
        }

        $this->assertDatabaseHas('audit_logs', ['action' => 'project.deleted', 'user_id' => $user->id]);
        $this->assertNotNull(Project::query()->find($otherProject->id));
    }

    public function test_deleting_the_account_removes_uploaded_files_too(): void
    {
        $user = User::factory()->member()->create();
        $other = $this->member();

        $this->addImage($user, $this->projectFor($user), 1);
        $this->addImage($other, $this->projectFor($other, 'Other'), 2);
        $this->assertCount(4, $this->storedFiles());

        $this->actingAs($user)->deleteJson('/api/v1/account', ['password' => 'Str0ng-Passw0rd!'])->assertNoContent();

        $remaining = $this->storedFiles();
        $this->assertCount(2, $remaining);
        $this->assertStringStartsWith('users/'.$other->ulid.'/', $remaining[0]);
        $this->assertTrue(Storage::disk('local')->exists($remaining[0]));
    }
}
