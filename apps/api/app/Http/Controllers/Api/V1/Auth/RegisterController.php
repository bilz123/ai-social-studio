<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Auth\RoleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->hasSession(), 400, 'Registration is only available from the web app.');

        $user = DB::transaction(function () use ($request): User {
            // Mass assignment is limited by User::$fillable; roles are assigned explicitly.
            $user = new User($request->safe()->only(['name', 'email', 'password']));
            $user->save();
            $user->assignRole(RoleCatalog::MEMBER);

            return $user;
        });

        $audit->record('auth.registered', $user, $user);
        $user->sendEmailVerificationNotification();

        Auth::login($user);
        $request->session()->regenerate();

        return (new UserResource($user))->response()->setStatusCode(201);
    }
}
