<?php

namespace App\Models;

use App\Enums\ScheduledPostStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\OwnedByUser;
use Database\Factories\ScheduledPostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledPost extends Model
{
    /** @use HasFactory<ScheduledPostFactory> */
    use HasFactory, HasPublicId, OwnedByUser;

    /** @var list<string> */
    protected $fillable = ['scheduled_for', 'timezone', 'caption', 'hashtags', 'platform_options'];

    protected function casts(): array
    {
        return [
            'status' => ScheduledPostStatus::class,
            'scheduled_for' => 'datetime',
            'hashtags' => 'array',
            'platform_options' => 'array',
            'dispatched_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GeneratedVideo, $this> */
    public function generatedVideo(): BelongsTo
    {
        return $this->belongsTo(GeneratedVideo::class);
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class)->withTrashed();
    }

    /** @return BelongsTo<PublishingJob, $this> */
    public function publishingJob(): BelongsTo
    {
        return $this->belongsTo(PublishingJob::class);
    }
}
