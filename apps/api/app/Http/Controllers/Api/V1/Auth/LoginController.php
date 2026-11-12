<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function store(LoginRequest $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->hasSession(), 400, 'Sign-in is only available from the web app.');

        $email = (string) $request->validated('email');
        $password = (string) $request->validated('password');

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            // Spend the same time hashing as a real check so response time does not reveal
            // whether an email is registered.
            Hash::make($password);
            $valid = false;
        } else {
            $valid = Hash::check($password, $user->getAuthPassword());
        }

        if (! $valid) {
            if ($user !== null) {
                $audit->record('auth.login_failed', $user, $user);
            }

            // One message for "unknown email" and "wrong password".
            throw ValidationException::withMessages(['email' => ['These credentials do not match our records.']]);
        }

        if (Hash::needsRehash($user->getAuthPassword())) {
            $user->forceFill(['password' => $password])->save();
        }

        Auth::login($user, $request->boolean('remember'));
        // New session id after authentication: prevents session fixation.
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();
        $audit->record('auth.login', $user, $user);

        return (new UserResource($user))->response();
    }

    public function destroy(Request $request, AuditLogger $audit): Response
    {
        $user = $request->user();

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $audit->record('auth.logout', $user, $user);

        return response()->noContent();
    }
}
