<?php

namespace Tests\Feature\Authorization;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Auth\RoleCatalog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_and_permissions_ship_with_the_schema(): void
    {
        $this->assertSame(['admin', 'member'], Role::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame(count(RoleCatalog::PERMISSIONS), Permission::query()->count());
    }

    public function test_members_get_studio_permissions_but_no_admin_permissions(): void
    {
        $member = User::factory()->member()->create();

        foreach (RoleCatalog::MEMBER_PERMISSIONS as $permission) {
            $this->assertTrue($member->hasPermission($permission), $permission);
            $this->assertTrue(Gate::forUser($member)->allows($permission), $permission);
        }

        foreach (['users.view', 'users.manage', 'audit-logs.view-all'] as $permission) {
            $this->assertFalse($member->hasPermission($permission), $permission);
            $this->assertTrue(Gate::forUser($member)->denies($permission), $permission);
        }
    }

    public function test_admins_hold_every_permission(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertEqualsCanonicalizing(array_keys(RoleCatalog::PERMISSIONS), $admin->permissionNames());
    }

    public function test_a_user_without_roles_has_no_permissions(): void
    {
        $user = User::factory()->create();

        $this->assertSame([], $user->permissionNames());
        $this->assertTrue(Gate::forUser($user)->denies('projects.manage'));
    }

    public function test_syncing_the_catalog_is_idempotent_and_repairs_drift(): void
    {
        $member = Role::query()->where('name', 'member')->firstOrFail();
        $member->permissions()->attach(Permission::query()->where('name', 'users.manage')->firstOrFail());
        $before = Permission::query()->count();

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, Permission::query()->count());
        $this->assertSame(2, Role::query()->count());
        $this->assertEqualsCanonicalizing(
            RoleCatalog::MEMBER_PERMISSIONS,
            $member->permissions()->pluck('name')->all(),
        );
    }

    public function test_the_admin_user_list_is_protected_by_permission(): void
    {
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();

        $member = User::factory()->member()->create();
        $this->actingAs($member)->getJson('/api/v1/admin/users')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_admins_can_list_users_without_sensitive_fields(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->member()->count(2)->create();

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/users')->assertOk();

        $this->assertCount(3, $response->json('data'));
        $content = (string) $response->getContent();
        foreach (['password', 'remember_token'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
        $this->assertSame(26, strlen($response->json('data.0.id')));
        $this->assertArrayNotHasKey('permissions', $response->json('data.0'));
    }

    public function test_granting_a_permission_through_a_role_takes_effect(): void
    {
        $user = User::factory()->member()->create();
        $this->assertTrue(Gate::forUser($user)->denies('users.view'));

        $role = Role::query()->create(['name' => 'support', 'label' => 'Support']);
        $role->permissions()->attach(Permission::query()->where('name', 'users.view')->firstOrFail());
        $user->assignRole('support');

        $fresh = User::query()->findOrFail($user->id);
        $this->assertTrue(Gate::forUser($fresh)->allows('users.view'));
        $this->actingAs($fresh)->getJson('/api/v1/admin/users')->assertOk();
    }
}
