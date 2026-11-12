<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Idempotent; also applied by a migration so a fresh database always has the roles.
        $this->call(RolesAndPermissionsSeeder::class);
    }
}
