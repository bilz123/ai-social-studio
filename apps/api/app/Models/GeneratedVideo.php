<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\OwnedByUser;
use Database\Factories\GeneratedVideoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeneratedVideo extends Model
{
    /** @use HasFactory<GeneratedVideoFactory> */
    use HasFactory, HasPublicId, OwnedByUser;

    /**
     * Nothing is mass assignable: videos are created by the pipeline and approval is a deliberate
     * state change made through dedicated code.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'approval_status' => ApprovalStatus::class,
            'has_audio' => 'boolean',
            'is_current' => 'boolean',
            'reviewed_at' => 'datetime',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
            'bytes' => 'integer',
        ];
    }

    /** @return BelongsTo<VideoGenerationJob, $this> */
    public function generationJob(): BelongsTo
    {
        return $this->belongsTo(VideoGenerationJob::class, 'generation_job_id');
    }

    /** @return HasMany<PublishingJob, $this> */
    public function publishingJobs(): HasMany
    {
        return $this->hasMany(PublishingJob::class);
    }

    public function isApproved(): bool
    {
        return $this->approval_status === ApprovalStatus::Approved;
    }
}
