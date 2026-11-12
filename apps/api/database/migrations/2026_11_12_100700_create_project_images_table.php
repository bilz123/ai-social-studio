<?php

use App\Support\Database\SqlCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_images', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->string('disk', 32);
            $table->string('object_key', 255)->unique();
            $table->string('original_name', 255);
            $table->string('mime', 64);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedSmallInteger('position');
            $table->string('role', 16)->default('other');
            $table->string('note', 255)->nullable();
            $table->string('status', 16)->default('pending_upload');
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamps();

            // An image can only belong to a project owned by the same user.
            $table->foreign(['project_id', 'user_id'])
                ->references(['id', 'user_id'])->on('projects')
                ->cascadeOnDelete();
            $table->unique(['project_id', 'position']);
            $table->index(['user_id', 'status']);
            $table->index('sha256');
        });

        SqlCheck::add('project_images', 'chk_project_images_status', "status IN ('pending_upload','uploaded','validated','rejected')");
        SqlCheck::add('project_images', 'chk_project_images_role', "role IN ('hero','detail','lifestyle','other')");
    }

    public function down(): void
    {
        Schema::dropIfExists('project_images');
    }
};
