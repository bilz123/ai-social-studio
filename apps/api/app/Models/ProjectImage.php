<?php

namespace App\Models;

use App\Enums\ImageStatus;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\OwnedByUser;
use Database\Factories\ProjectImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectImage extends Model
{
    /** @use HasFactory<ProjectImageFactory> */
    use HasFactory, HasPublicId, OwnedByUser;

    /**
     * Only user-editable descriptive fields; storage and validation fields are system-set.
     *
     * @var list<string>
     */
    protected $fillable = ['role', 'note'];

    protected function casts(): array
    {
        return [
            'status' => ImageStatus::class,
            'bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
