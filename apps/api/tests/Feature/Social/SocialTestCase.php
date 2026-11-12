<?php

namespace Tests\Feature\Social;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Base class for the social integration tests. Provider developer credentials are fake test
 * values and every provider HTTP call is answered by Http::fake, so no test ever reaches Meta or
 * Google. Any request without a matching fake fails the test.
 */
abstract class SocialTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('social.providers.facebook.client_id', 'fb-app-id');
        config()->set('social.providers.facebook.client_secret', 'fb-app-secret');
        config()->set('social.providers.instagram.client_id', 'ig-app-id');
        config()->set('social.providers.instagram.client_secret', 'ig-app-secret');
        config()->set('social.providers.youtube.client_id', 'google-client-id');
        config()->set('social.providers.youtube.client_secret', 'google-client-secret');

        Sleep::fake();
        Http::preventStrayRequests();
    }

    protected function member(): User
    {
        return User::factory()->member()->create();
    }

    /**
     * Start the connection and return the provider URL and its decoded query string.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    protected function startConnect(User $user, string $platform): array
    {
        $url = (string) $this->actingAs($user)
            ->postJson("/api/v1/social/{$platform}/connect")
            ->assertOk()
            ->json('data.authorization_url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return [$url, $query];
    }

    /** @param  array<string, mixed>  $payload */
    protected function callback(User $user, string $platform, array $payload): TestResponse
    {
        return $this->actingAs($user)->postJson("/api/v1/social/{$platform}/callback", $payload);
    }

    /**
     * Run the whole start + callback sequence and return the callback response.
     */
    protected function connectWithCode(User $user, string $platform, string $code = 'auth-code'): TestResponse
    {
        [, $query] = $this->startConnect($user, $platform);

        return $this->callback($user, $platform, ['code' => $code, 'state' => $query['state']]);
    }

    /** @return array<string, string> */
    protected function queryOf(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }
}
