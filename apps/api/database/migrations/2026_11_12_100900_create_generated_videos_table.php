<?php

use App\Support\Database\SqlCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_videos', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('generation_job_id');
            $table->string('disk', 32);
            $table->string('object_key', 255)->unique();
            $table->string('thumbnail_key', 255)->nullable();
            $table->string('aspect_ratio', 8);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('duration_ms');
            $table->unsignedTinyInteger('fps')->nullable();
            $table->string('video_codec', 16)->nullable();
            $table->string('audio_codec', 16)->nullable();
            $table->boolean('has_audio')->default(false);
            $table->unsignedBigInteger('bytes');
            $table->boolean('is_current')->default(true);
            // Approval gate: only an approved video may be published.
            $table->string('approval_status', 24)->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_comment')->nullable();
            $table->timestamps();

            $table->foreign(['generation_job_id', 'user_id'])
                ->references(['id', 'user_id'])->on('video_generation_jobs')
                ->cascadeOnDelete();
            $table->unique(['id', 'user_id']);
            $table->index('generation_job_id');
            $table->index(['user_id', 'approval_status']);
        });

        SqlCheck::add('generated_videos', 'chk_generated_videos_approval', "approval_status IN ('pending','approved','rejected','changes_requested')");
        SqlCheck::add('generated_videos', 'chk_generated_videos_duration', 'duration_ms > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_videos');
    }
};
