<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\OwnedByUser;
use Database\Factories\VideoGenerationJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoGenerationJob extends Model
{
    /** @use HasFactory<VideoGenerationJobFactory> */
    use HasFactory, HasPublicId, OwnedByUser;

    /**
     * Only what a user may choose when requesting a generation. Status, progress, cost and AI
     * identifiers are written by the pipeline, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = ['style_preset', 'aspect_ratio', 'params'];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'params' => 'array',
            'storyboard' => 'array',
            'duration_ms' => 'integer',
            'progress' => 'integer',
            'cost_cents' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<GeneratedVideo, $this> */
    public function generatedVideos(): HasMany
    {
        return $this->hasMany(GeneratedVideo::class, 'generation_job_id');
    }
}
