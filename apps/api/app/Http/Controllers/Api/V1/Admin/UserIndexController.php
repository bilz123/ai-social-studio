<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserIndexController extends Controller
{
    /** Route is guarded by the `users.view` permission (see routes/api/v1.php). */
    public function __invoke(): AnonymousResourceCollection
    {
        $users = User::query()
            ->with('roles')
            ->orderByDesc('id')
            ->cursorPaginate(25);

        return AdminUserResource::collection($users);
    }
}
