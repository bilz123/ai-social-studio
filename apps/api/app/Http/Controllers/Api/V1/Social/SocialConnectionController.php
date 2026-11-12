<?php

namespace App\Http\Controllers\Api\V1\Social;

use App\Enums\SocialPlatform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Social\CallbackRequest;
use App\Http\Resources\SocialAccountResource;
use App\Services\Social\SocialConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SocialConnectionController extends Controller
{
    public function __construct(private readonly SocialConnectionService $connections) {}

    /** Start OAuth: returns the provider consent URL for the SPA to navigate to. */
    public function connect(Request $request, string $platform): JsonResponse
    {
        $started = $this->connections->begin($request->user(), $this->platform($platform));

        return response()->json(['data' => [
            'authorization_url' => $started['authorization_url'],
            'expires_at' => $started['expires_at']->toIso8601ZuluString(),
        ]]);
    }

    /** Finish OAuth with the code and state the provider sent to the SPA. */
    public function callback(CallbackRequest $request, string $platform): AnonymousResourceCollection
    {
        $accounts = $this->connections->complete(
            $request->user(),
            $this->platform($platform),
            (string) $request->validated('state'),
            $request->validated('code'),
            $request->validated('error'),
        );

        return SocialAccountResource::collection($accounts->load('tokens'));
    }

    private function platform(string $slug): SocialPlatform
    {
        return SocialPlatform::fromSlug($slug) ?? abort(404);
    }
}
