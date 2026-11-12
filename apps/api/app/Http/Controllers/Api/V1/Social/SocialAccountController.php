<?php

namespace App\Http\Controllers\Api\V1\Social;

use App\Http\Controllers\Controller;
use App\Http\Requests\Social\UpdateSocialAccountRequest;
use App\Http\Resources\SocialAccountResource;
use App\Models\SocialAccount;
use App\Services\AuditLogger;
use App\Services\Social\SocialConnectionService;
use App\Services\Social\SocialTokenManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class SocialAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $accounts = $request->user()->socialAccounts()
            ->with('tokens')
            ->orderBy('platform')
            ->orderBy('name')
            ->get();

        return SocialAccountResource::collection($accounts);
    }

    /** Turn publishing to this account on or off without disconnecting it. */
    public function update(UpdateSocialAccountRequest $request, SocialAccount $account, AuditLogger $audit): SocialAccountResource
    {
        Gate::authorize('update', $account);

        $account->fill(['is_enabled' => $request->boolean('is_enabled')])->save();

        $audit->record('social.account.updated', $request->user(), $account, ['is_enabled' => $account->is_enabled], $request);

        return new SocialAccountResource($account->load('tokens'));
    }

    /**
     * "Check connection": renews the token if it is due and re-reads the profile and permissions
     * from the provider. If the provider no longer accepts the connection, the account is marked
     * "needs reconnect" and a 409 problem explains why.
     */
    public function check(Request $request, SocialAccount $account, SocialTokenManager $tokens, AuditLogger $audit): SocialAccountResource
    {
        Gate::authorize('update', $account);

        $account = $tokens->check($account);

        $audit->record('social.account.checked', $request->user(), $account, ['status' => $account->status->value], $request);

        return new SocialAccountResource($account->load('tokens'));
    }

    public function destroy(Request $request, SocialAccount $account, SocialConnectionService $connections): Response
    {
        Gate::authorize('delete', $account);

        $connections->disconnect($request->user(), $account);

        return response()->noContent();
    }
}
