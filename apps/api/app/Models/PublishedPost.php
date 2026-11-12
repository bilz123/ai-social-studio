<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\OwnedByUser;
use Database\Factories\PublishedPostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishedPost extends Model
{
    /** @use HasFactory<PublishedPostFactory> */
    use HasFactory, HasPublicId, OwnedByUser;

    /** Written only by the publishing pipeline. @var list<string> */
    protected $fillable = [];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    /** @return BelongsTo<PublishingJob, $this> */
    public function publishingJob(): BelongsTo
    {
        return $this->belongsTo(PublishingJob::class);
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class)->withTrashed();
    }
}
