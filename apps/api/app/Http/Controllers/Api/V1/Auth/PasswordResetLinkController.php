<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController extends Controller
{
    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        // The outcome (sent / unknown email / throttled) is deliberately not revealed, so this
        // endpoint cannot be used to discover which emails have accounts.
        Password::sendResetLink(['email' => $request->validated('email')]);

        return response()->json([
            'message' => 'If an account exists for that email address, a password reset link has been sent.',
        ], 202);
    }
}
