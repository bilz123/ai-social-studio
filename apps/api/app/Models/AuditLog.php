<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only: rows can be created but never edited or deleted through the model.
 * (Retention pruning is done with a dedicated query-builder job, not through Eloquent.)
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['action', 'subject_type', 'subject_id', 'ip', 'user_agent', 'meta'];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Audit log entries are immutable.');
        });

        static::deleting(function (): void {
            throw new LogicException('Audit log entries cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
