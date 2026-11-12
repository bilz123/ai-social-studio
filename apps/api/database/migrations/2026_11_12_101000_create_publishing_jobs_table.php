<?php

use App\Support\Database\SqlCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One job per (video, destination account); a multi-account publish creates several rows.
        Schema::create('publishing_jobs', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('generated_video_id');
            $table->unsignedBigInteger('social_account_id');
            $table->string('status', 16)->default('pending');
            $table->text('caption')->nullable();
            $table->json('hashtags')->nullable();
            $table->json('platform_options')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->string('last_error_message', 500)->nullable();
            $table->string('platform_container_id', 128)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // Video and destination account must both belong to the publishing user.
            $table->foreign(['generated_video_id', 'user_id'])
                ->references(['id', 'user_id'])->on('generated_videos')
                ->cascadeOnDelete();
            $table->foreign(['social_account_id', 'user_id'])
                ->references(['id', 'user_id'])->on('social_accounts')
                ->cascadeOnDelete();
            $table->unique(['user_id', 'idempotency_key']);
            $table->unique(['id', 'user_id']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['user_id', 'status']);
            $table->index('generated_video_id');
            $table->index('social_account_id');
        });

        SqlCheck::add('publishing_jobs', 'chk_publishing_jobs_status', "status IN ('pending','uploading','processing','published','failed','canceled')");
    }

    public function down(): void
    {
        Schema::dropIfExists('publishing_jobs');
    }
};
