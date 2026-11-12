<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The live post a successful publishing job produced on a platform.
        Schema::create('published_posts', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('publishing_job_id');
            $table->unsignedBigInteger('social_account_id');
            $table->string('external_post_id', 128);
            $table->string('external_url', 2048)->nullable();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->foreign(['publishing_job_id', 'user_id'])
                ->references(['id', 'user_id'])->on('publishing_jobs')
                ->cascadeOnDelete();
            $table->foreign(['social_account_id', 'user_id'])
                ->references(['id', 'user_id'])->on('social_accounts')
                ->cascadeOnDelete();
            $table->unique('publishing_job_id');
            $table->unique(['social_account_id', 'external_post_id']);
            $table->index(['user_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('published_posts');
    }
};
