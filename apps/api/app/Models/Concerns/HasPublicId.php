<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Exposes records by a random ULID (`ulid` column) instead of the auto-increment id, so ids in
 * URLs and responses cannot be enumerated. Route model binding resolves by ULID too.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model): void {
            if (empty($model->ulid)) {
                $model->ulid = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @param Builder<static> $query */
    public function scopeByPublicId(Builder $query, string $ulid): Builder
    {
        return $query->where('ulid', $ulid);
    }
}
