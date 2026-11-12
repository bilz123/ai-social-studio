<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Default rule for every user-owned record: you can only see and change your own. There is
 * deliberately no admin override here; administrators get explicit, separate permissions
 * (users.view, audit-logs.view-all, …) instead of silent access to other users' content.
 */
abstract class OwnedRecordPolicy
{
    public function viewAny(User $user): bool
    {
        // Listing is always scoped to the caller through ownedBy()/relations.
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $record): bool
    {
        return $this->owns($user, $record);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->owns($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->owns($user, $record);
    }

    protected function owns(User $user, Model $record): bool
    {
        return (int) $record->getAttribute('user_id') === (int) $user->getKey();
    }
}
