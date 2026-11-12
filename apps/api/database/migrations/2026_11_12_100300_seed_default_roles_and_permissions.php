<?php

use App\Support\Auth\RoleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Reference data the application cannot run without (registration assigns the "member" role), so
// it ships with the schema. RoleCatalog::sync() is idempotent; the seeder calls it as well.
return new class extends Migration
{
    public function up(): void
    {
        RoleCatalog::sync();
    }

    public function down(): void
    {
        DB::table('permission_role')->delete();
        DB::table('permissions')->delete();
        DB::table('roles')->delete();
    }
};
