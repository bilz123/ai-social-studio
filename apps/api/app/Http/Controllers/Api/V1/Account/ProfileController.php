<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ProfileController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function update(UpdateProfileRequest $request, AuditLogger $audit): UserResource
    {
        $user = $request->user();
        $data = Arr::only($request->validated(), ['name', 'email']);
        $emailChanged = isset($data['email']) && $data['email'] !== $user->email;

        $user->fill($data);

        if ($emailChanged) {
            // The new address is unverified until its owner clicks the link we send there.
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
            $audit->record('account.email_changed', $user, $user);
        }

        $audit->record('account.profile_updated', $user, $user, ['fields' => array_keys($data)]);

        return new UserResource($user);
    }
}
