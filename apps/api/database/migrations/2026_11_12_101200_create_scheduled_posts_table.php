<?php

use App\Support\Database\SqlCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_posts', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('generated_video_id');
            $table->unsignedBigInteger('social_account_id');
            // Set when the scheduler turns this entry into a publishing job.
            $table->unsignedBigInteger('publishing_job_id')->nullable();
            // Always stored in UTC; `timezone` remembers how the user entered it.
            $table->timestamp('scheduled_for');
            $table->string('timezone', 64)->default('UTC');
            $table->string('status', 16)->default('scheduled');
            $table->text('caption')->nullable();
            $table->json('hashtags')->nullable();
            $table->json('platform_options')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->foreign(['generated_video_id', 'user_id'])
                ->references(['id', 'user_id'])->on('generated_videos')
                ->cascadeOnDelete();
            $table->foreign(['social_account_id', 'user_id'])
                ->references(['id', 'user_id'])->on('social_accounts')
                ->cascadeOnDelete();
            // A NULL publishing_job_id is not checked (composite foreign keys skip NULL parts).
            $table->foreign(['publishing_job_id', 'user_id'])
                ->references(['id', 'user_id'])->on('publishing_jobs')
                ->cascadeOnDelete();
            $table->unique('publishing_job_id');
            // The scheduler scan.
            $table->index(['status', 'scheduled_for']);
            $table->index(['user_id', 'scheduled_for']);
            $table->index('generated_video_id');
            $table->index('social_account_id');
        });

        SqlCheck::add('scheduled_posts', 'chk_scheduled_posts_status', "status IN ('scheduled','dispatched','canceled','failed')");
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_posts');
    }
};
