<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Project;
use Illuminate\Http\Request;

trait FindsOwnedProject
{
    /**
     * Looks the project up among the caller's own projects only. Someone else's project (or a
     * deleted or unknown one) is a plain 404, so its existence is not revealed.
     */
    protected function ownedProject(Request $request, string $ulid): Project
    {
        return $request->user()->projects()->where('ulid', $ulid)->firstOrFail();
    }
}
