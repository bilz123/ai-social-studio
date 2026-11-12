<?php

namespace App\Models;

use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialProvider;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\OwnedByUser;
use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use HasFactory, HasPublicId, OwnedByUser, SoftDeletes;

    /**
     * Accounts are created by the OAuth callback code, never from request input; the only field a
     * user may change directly is the enable switch.
     *
     * @var list<string>
     */
    protected $fillable = ['is_enabled'];

    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'platform' => SocialPlatform::class,
            'status' => SocialAccountStatus::class,
            'is_enabled' => 'boolean',
            'capabilities' => 'array',
            'last_publish_at' => 'datetime',
        ];
    }

    /** @return HasMany<SocialToken, $this> */
    public function tokens(): HasMany
    {
        return $this->hasMany(SocialToken::class);
    }

    /** @return HasMany<PublishingJob, $this> */
    public function publishingJobs(): HasMany
    {
        return $this->hasMany(PublishingJob::class);
    }
}
