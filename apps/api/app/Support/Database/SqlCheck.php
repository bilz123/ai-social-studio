<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Adds a CHECK constraint on MySQL (8.0.16+ enforces them). SQLite, used by the test suite,
 * cannot add constraints to an existing table, so there the call is a no-op and the same rules are
 * covered by validation and enum casts instead.
 */
final class SqlCheck
{
    public static function add(string $table, string $name, string $expression): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
    }
}
