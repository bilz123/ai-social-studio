<?php

namespace App\Models;

use App\Enums\PublishingStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\OwnedByUser;
use Database\Factories\PublishingJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PublishingJob extends Model
{
    /** @use HasFactory<PublishingJobFactory> */
    use HasFactory, HasPublicId, OwnedByUser;

    /** @var list<string> */
    protected $fillable = ['caption', 'hashtags', 'platform_options'];

    protected function casts(): array
    {
        return [
            'status' => PublishingStatus::class,
            'hashtags' => 'array',
            'platform_options' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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

    /** @return HasOne<PublishedPost, $this> */
    public function publishedPost(): HasOne
    {
        return $this->hasOne(PublishedPost::class);
    }
}
