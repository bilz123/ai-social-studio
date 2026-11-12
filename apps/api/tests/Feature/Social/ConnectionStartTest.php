<?php

namespace Tests\Feature\Social;

use App\Models\OAuthState;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ConnectionStartTest extends SocialTestCase
{
    public function test_every_social_route_requires_sign_in(): void
    {
        $this->getJson('/api/v1/social/platforms')->assertUnauthorized();
        $this->getJson('/api/v1/social/accounts')->assertUnauthorized();
        $this->postJson('/api/v1/social/facebook/connect')->assertUnauthorized();
        $this->postJson('/api/v1/social/facebook/callback', ['state' => 'x'])->assertUnauthorized();
    }

    public function test_unknown_platforms_are_not_found(): void
    {
        $this->actingAs($this->member())->postJson('/api/v1/social/tiktok/connect')->assertNotFound();
    }

    public function test_the_platform_list_reports_configuration_and_connected_counts(): void
    {
        $user = $this->member();
        SocialAccount::factory()->for($user)->facebook()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/social/platforms')->assertOk();

        $response->assertJsonPath('data.0.id', 'facebook')
            ->assertJsonPath('data.0.configured', true)
            ->assertJsonPath('data.0.connected_count', 1)
            ->assertJsonPath('data.1.id', 'instagram')
            ->assertJsonPath('data.1.connected_count', 0)
            ->assertJsonPath('data.2.id', 'youtube')
            ->assertJsonPath('data.2.supports_refresh', true);
    }

    public function test_a_platform_without_developer_credentials_is_reported_and_cannot_start(): void
    {
        config()->set('social.providers.instagram.client_id', null);
        config()->set('social.providers.instagram.client_secret', '');
        Http::fake();
        $user = $this->member();

        $this->actingAs($user)->getJson('/api/v1/social/platforms')
            ->assertJsonPath('data.1.configured', false)
            ->assertJsonPath('data.1.missing_configuration', ['INSTAGRAM_APP_ID', 'INSTAGRAM_APP_SECRET']);

        $this->actingAs($user)->postJson('/api/v1/social/instagram/connect')
            ->assertStatus(503)
            ->assertJsonPath('code', 'integration_not_configured');

        $this->assertDatabaseCount('oauth_states', 0);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'social.connect.unavailable']);
        Http::assertNothingSent();
    }

    public function test_facebook_connect_returns_the_meta_consent_url(): void
    {
        $user = $this->member();

        [$url, $query] = $this->startConnect($user, 'facebook');

        $this->assertStringStartsWith('https://www.facebook.com/v26.0/dialog/oauth?', $url);
        $this->assertSame('fb-app-id', $query['client_id']);
        $this->assertSame('http://localhost:8080/connections/callback/facebook', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('pages_show_list,pages_read_engagement,pages_manage_posts', $query['scope']);
        $this->assertSame(64, strlen($query['state']));
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'social.connect.started']);
    }

    public function test_the_state_is_stored_only_as_a_hash(): void
    {
        $user = $this->member();

        [, $query] = $this->startConnect($user, 'facebook');

        $this->assertDatabaseHas('oauth_states', [
            'user_id' => $user->id,
            'platform' => 'facebook_page',
            'state_hash' => hash('sha256', $query['state']),
        ]);
        $this->assertDatabaseMissing('oauth_states', ['state_hash' => $query['state']]);
    }

    public function test_instagram_connect_uses_instagram_login(): void
    {
        [$url, $query] = $this->startConnect($this->member(), 'instagram');

        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $url);
        $this->assertSame('ig-app-id', $query['client_id']);
        $this->assertSame('http://localhost:8080/connections/callback/instagram', $query['redirect_uri']);
        $this->assertSame('instagram_business_basic,instagram_business_content_publish', $query['scope']);
    }

    public function test_youtube_connect_uses_google_oauth_with_pkce_and_offline_access(): void
    {
        [$url, $query] = $this->startConnect($this->member(), 'youtube');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertSame('google-client-id', $query['client_id']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(
            'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly',
            $query['scope'],
        );

        // The challenge is derived from a verifier that is kept server side, encrypted.
        $state = OAuthState::query()->firstOrFail();
        $verifier = $state->code_verifier;
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->assertSame($expected, $query['code_challenge']);
        $this->assertNotSame($verifier, DB::table('oauth_states')->value('code_verifier'));
    }

    public function test_an_unknown_state_is_rejected_before_any_provider_call(): void
    {
        Http::fake();
        $user = $this->member();

        $this->postCallback($user, 'facebook', ['code' => 'c', 'state' => 'never-issued'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_oauth_state');

        Http::assertNothingSent();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'social.connect.failed']);
    }

    public function test_a_state_cannot_be_used_by_another_user(): void
    {
        Http::fake();
        [, $query] = $this->startConnect($this->member(), 'facebook');

        $this->postCallback($this->member(), 'facebook', ['code' => 'c', 'state' => $query['state']])
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_oauth_state');

        Http::assertNothingSent();
    }

    public function test_a_state_cannot_be_used_for_another_platform(): void
    {
        Http::fake();
        $user = $this->member();
        [, $query] = $this->startConnect($user, 'instagram');

        $this->postCallback($user, 'facebook', ['code' => 'c', 'state' => $query['state']])
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_oauth_state');

        Http::assertNothingSent();
    }

    public function test_an_expired_state_is_rejected(): void
    {
        Http::fake();
        $user = $this->member();
        [, $query] = $this->startConnect($user, 'facebook');

        $this->travel(11)->minutes();

        $this->postCallback($user, 'facebook', ['code' => 'c', 'state' => $query['state']])
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_oauth_state');

        Http::assertNothingSent();
    }

    public function test_a_state_works_only_once(): void
    {
        $user = $this->member();
        [, $query] = $this->startConnect($user, 'facebook');

        // First use: the provider rejects the (fake) code, but the state is burned either way.
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'bad code', 'type' => 'OAuthException', 'code' => 100]], 400)]);
        $this->postCallback($user, 'facebook', ['code' => 'c', 'state' => $query['state']])->assertStatus(400);

        $this->postCallback($user, 'facebook', ['code' => 'c', 'state' => $query['state']])
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_oauth_state');
    }

    public function test_a_denied_authorization_is_reported_and_connects_nothing(): void
    {
        Http::fake();
        $user = $this->member();
        [, $query] = $this->startConnect($user, 'facebook');

        $this->postCallback($user, 'facebook', ['state' => $query['state'], 'error' => 'access_denied'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'authorization_denied');

        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'social.connect.failed']);
        Http::assertNothingSent();
    }

    public function test_a_callback_without_a_code_or_error_is_treated_as_denied(): void
    {
        Http::fake();
        $user = $this->member();
        [, $query] = $this->startConnect($user, 'facebook');

        $this->postCallback($user, 'facebook', ['state' => $query['state']])
            ->assertStatus(400)
            ->assertJsonPath('code', 'authorization_denied');
    }

    public function test_the_callback_requires_a_state(): void
    {
        $this->postCallback($this->member(), 'facebook', ['code' => 'c'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['state'], 'errors');
    }
}
