<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_images', function (Blueprint $table) {
            // The generated preview lives next to the original; the original is never modified.
            $table->string('thumbnail_key', 255)->nullable()->after('object_key');
            $table->unsignedInteger('thumbnail_bytes')->nullable()->after('thumbnail_key');
        });
    }

    public function down(): void
    {
        Schema::table('project_images', function (Blueprint $table) {
            $table->dropColumn(['thumbnail_key', 'thumbnail_bytes']);
        });
    }
};
