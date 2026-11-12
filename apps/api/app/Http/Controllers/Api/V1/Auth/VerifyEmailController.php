<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    /**
     * Reached through the `signed:relative` middleware, so the link is untampered and unexpired.
     * It still must match a real user and that user's current email (an old link stops working
     * after the address changes).
     */
    public function verify(Request $request, string $id, string $hash, AuditLogger $audit): JsonResponse
    {
        $user = User::query()->byPublicId($id)->first();

        abort_if(
            $user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash),
            403,
        );

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
            $audit->record('auth.email_verified', $user, $user);
        }

        return response()->json(['data' => ['verified' => true]]);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['message' => 'Verification email sent if your address is not yet verified.'], 202);
    }
}
