<?php

use App\Support\Database\SqlCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per generation attempt; "regenerate" creates a new row with version + 1.
        Schema::create('video_generation_jobs', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id');
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('queued');
            $table->string('style_preset', 32)->default('cinematic_dolly');
            $table->unsignedInteger('duration_ms')->default(10000);
            $table->string('aspect_ratio', 8)->default('9:16');
            $table->json('params')->nullable();
            $table->json('storyboard')->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('provider_job_ref', 128)->nullable();
            $table->string('ai_job_id', 64)->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('stage', 32)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('cost_cents')->default(0);
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign(['project_id', 'user_id'])
                ->references(['id', 'user_id'])->on('projects')
                ->cascadeOnDelete();
            $table->unique(['project_id', 'version']);
            $table->unique(['user_id', 'idempotency_key']);
            $table->unique(['id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index('ai_job_id');
        });

        SqlCheck::add('video_generation_jobs', 'chk_vgj_progress', 'progress BETWEEN 0 AND 100');
        SqlCheck::add('video_generation_jobs', 'chk_vgj_duration', 'duration_ms > 0');
        SqlCheck::add('video_generation_jobs', 'chk_vgj_status', "status IN ('queued','analyzing','planning','generating','postprocessing','ready_for_review','failed','canceled')");
    }

    public function down(): void
    {
        Schema::dropIfExists('video_generation_jobs');
    }
};
