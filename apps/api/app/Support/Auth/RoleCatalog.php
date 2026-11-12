<?php

namespace App\Support\Auth;

use Illuminate\Support\Facades\DB;

/**
 * Source of truth for the built-in roles and permissions. `sync()` is idempotent: it creates what
 * is missing, refreshes labels and makes each role hold exactly the permissions listed here.
 * It talks to the tables directly so old migrations keep working if the models change later.
 */
final class RoleCatalog
{
    public const MEMBER = 'member';

    public const ADMIN = 'admin';

    /** @var array<string, string> permission name => label */
    public const PERMISSIONS = [
        'projects.manage' => 'Create and manage own projects',
        'videos.generate' => 'Generate videos',
        'videos.publish' => 'Publish and schedule videos',
        'social-accounts.manage' => 'Connect and manage social accounts',
        'users.view' => 'View all users',
        'users.manage' => 'Manage users and roles',
        'audit-logs.view-all' => 'View every audit log entry',
    ];

    /** Permissions every normal user gets. Admin-only ones (users.*, audit-logs.*) are excluded. */
    public const MEMBER_PERMISSIONS = [
        'projects.manage',
        'videos.generate',
        'videos.publish',
        'social-accounts.manage',
    ];

    public static function sync(): void
    {
        $now = now();

        $permissionRows = [];
        foreach (self::PERMISSIONS as $name => $label) {
            $permissionRows[] = ['name' => $name, 'label' => $label, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('permissions')->upsert($permissionRows, ['name'], ['label', 'updated_at']);

        DB::table('roles')->upsert([
            ['name' => self::MEMBER, 'label' => 'Member', 'description' => 'Regular user of the studio.', 'created_at' => $now, 'updated_at' => $now],
            ['name' => self::ADMIN, 'label' => 'Administrator', 'description' => 'Full access, including user administration.', 'created_at' => $now, 'updated_at' => $now],
        ], ['name'], ['label', 'description', 'updated_at']);

        /** @var array<string, int> $permissionIds */
        $permissionIds = DB::table('permissions')->pluck('id', 'name')->all();
        /** @var array<string, int> $roleIds */
        $roleIds = DB::table('roles')->pluck('id', 'name')->all();

        $assignments = [
            self::MEMBER => self::MEMBER_PERMISSIONS,
            self::ADMIN => array_keys(self::PERMISSIONS),
        ];

        foreach ($assignments as $role => $names) {
            $desired = array_values(array_map(fn (string $name): int => $permissionIds[$name], $names));
            $roleId = $roleIds[$role];

            DB::table('permission_role')
                ->where('role_id', $roleId)
                ->whereNotIn('permission_id', $desired)
                ->delete();

            DB::table('permission_role')->insertOrIgnore(array_map(
                fn (int $permissionId): array => [
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $desired,
            ));
        }
    }
}
