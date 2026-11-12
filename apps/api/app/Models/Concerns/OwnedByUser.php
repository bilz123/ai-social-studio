<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For models with a `user_id` owner column. Always query through `ownedBy($user)` (or the user's
 * relations) so one user can never reach another user's rows.
 */
trait OwnedByUser
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<static> $query */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where($this->qualifyColumn('user_id'), $user->getKey());
    }

    public function isOwnedBy(User $user): bool
    {
        return (int) $this->getAttribute('user_id') === (int) $user->getKey();
    }
}
