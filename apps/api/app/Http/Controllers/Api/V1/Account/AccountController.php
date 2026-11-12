<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\DeleteAccountRequest;
use App\Services\AuditLogger;
use App\Services\Social\SocialConnectionService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    /**
     * Permanently deletes the signed-in user and everything they own. Foreign keys cascade to
     * projects, images, jobs, videos, social accounts and (encrypted) tokens. Grants at the
     * social providers are revoked first, best effort.
     */
    public function destroy(DeleteAccountRequest $request, AuditLogger $audit, SocialConnectionService $social): Response
    {
        $user = $request->user();

        $social->revokeAllFor($user);

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        DB::transaction(function () use ($user, $audit, $request): void {
            // The actor is intentionally null: the user row is about to disappear. Only the public
            // id is kept, no email or name.
            $audit->record('account.deleted', null, null, ['user_id' => $user->ulid], $request);

            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->notifications()->delete();
            $user->delete();
        });

        return response()->noContent();
    }
}
