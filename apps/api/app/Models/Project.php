<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\OwnedByUser;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasPublicId, OwnedByUser, SoftDeletes;

    /**
     * `user_id`, `ulid` and `status` transitions are set in code, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title', 'product_name', 'product_description', 'language', 'tone', 'brand_notes', 'target_audience',
    ];

    protected function casts(): array
    {
        return ['status' => ProjectStatus::class];
    }

    /** @return HasMany<ProjectImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProjectImage::class)->orderBy('position');
    }

    /**
     * The first image in the project's order; used as the card picture in lists.
     *
     * @return HasOne<ProjectImage, $this>
     */
    public function coverImage(): HasOne
    {
        return $this->hasOne(ProjectImage::class)->ofMany('position', 'min');
    }

    /** @return HasMany<VideoGenerationJob, $this> */
    public function videoGenerationJobs(): HasMany
    {
        return $this->hasMany(VideoGenerationJob::class);
    }
}
