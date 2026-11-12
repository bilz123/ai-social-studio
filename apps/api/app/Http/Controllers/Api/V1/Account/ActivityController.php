<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityController extends Controller
{
    /** The signed-in user's own security activity (logins, password changes, …), newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $logs = $request->user()
            ->auditLogs()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(20);

        return AuditLogResource::collection($logs);
    }
}
