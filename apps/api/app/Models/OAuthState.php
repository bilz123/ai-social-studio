<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OAuthState extends Model
{
    use Prunable;

    protected $table = 'oauth_states';

    public const UPDATED_AT = null;

    /** Created only by OAuthStateService (forceFill); never from request input. */
    protected $guarded = ['*'];

    protected $hidden = ['state_hash', 'code_verifier'];

    protected function casts(): array
    {
        return [
            'code_verifier' => 'encrypted',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Expired rows are useless after a day; `model:prune` (scheduled) removes them.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('expires_at', '<', now()->subDay());
    }
}
