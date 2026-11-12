<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Services\AuditLogger;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class PasswordController extends Controller
{
    public function update(ChangePasswordRequest $request, AuditLogger $audit): Response
    {
        $user = $request->user();
        $password = (string) $request->validated('password');

        $user->forceFill(['password' => $password])->save();

        if ($request->hasSession()) {
            // Sign every other browser/device out; keep this one and give it a fresh session id.
            Auth::guard('web')->logoutOtherDevices($password);
            $request->session()->regenerate();
        }

        $audit->record('account.password_changed', $user, $user);
        $user->notify(new PasswordChangedNotification);

        return response()->noContent();
    }
}
