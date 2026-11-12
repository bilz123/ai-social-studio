<?php

namespace Database\Seeders;

use App\Support\Auth\RoleCatalog;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        RoleCatalog::sync();
    }
}
