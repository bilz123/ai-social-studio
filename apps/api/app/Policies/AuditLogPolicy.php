<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** Your own entries, or any entry with the explicit admin permission. */
    public function view(User $user, AuditLog $log): bool
    {
        return (int) $log->user_id === (int) $user->getKey()
            || $user->hasPermission('audit-logs.view-all');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditLog $log): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $log): bool
    {
        return false;
    }
}
