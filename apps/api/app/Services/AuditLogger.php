<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes append-only audit entries. `$meta` must never contain secrets (passwords, tokens, reset
 * links); prefer identifiers and booleans.
 */
class AuditLogger
{
    /** @param array<string, mixed> $meta */
    public function record(string $action, ?User $actor = null, ?Model $subject = null, array $meta = [], ?Request $request = null): AuditLog
    {
        $request ??= request();

        $log = new AuditLog;
        $log->forceFill([
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject !== null ? class_basename($subject) : null,
            // The public id, never the internal auto-increment id.
            'subject_id' => $subject !== null ? (string) $subject->getRouteKey() : null,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent() !== null ? Str::limit($request->userAgent(), 500, '') : null,
            'meta' => $meta === [] ? null : $meta,
        ])->save();

        return $log;
    }
}
