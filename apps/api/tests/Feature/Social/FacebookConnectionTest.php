<?php

namespace Tests\Feature\Social;

use App\Models\SocialAccount;
use App\Models\SocialToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class FacebookConnectionTest extends SocialTestCase
{
    /** @param  array<string, mixed>  $overrides */
    private function fakeFacebook(array $overrides = []): void
    {
        Http::fake(array_merge([
            'graph.facebook.com/*/oauth/access_token*' => fn (Request $request) => ($request['grant_type'] ?? null) === 'fb_exchange_token'
                ? Http::response(['access_token' => 'long-user-token', 'token_type' => 'bearer', 'expires_in' => 5183999])
                : Http::response(['access_token' => 'short-user-token', 'token_type' => 'bearer']),
            'graph.facebook.com/*/me/permissions*' => Http::response(['data' => [
                ['permission' => 'pages_show_list', 'status' => 'granted'],
                ['permission' => 'pages_read_engagement', 'status' => 'granted'],
                ['permission' => 'pages_manage_posts', 'status' => 'granted'],
                ['permission' => 'public_profile', 'status' => 'granted'],
            ]]),
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
                [
                    'id' => '111', 'name' => 'Acme Coffee', 'username' => 'acmecoffee', 'category' => 'Cafe', 'fan_count' => 1280,
                    'tasks' => ['CREATE_CONTENT', 'MANAGE'], 'access_token' => 'page-token-111',
                    'picture' => ['data' => ['url' => 'https://img.test/111.jpg']],
                ],
                ['id' => '222', 'name' => 'Acme Roasters', 'tasks' => ['ANALYZE'], 'access_token' => 'page-token-222'],
                ['id' => '333', 'name' => 'No Token Page', 'tasks' => []],
            ]]),
        ], $overrides));
    }

    public function test_it_connects_the_pages_the_user_shared(): void
    {
        $this->fakeFacebook();
        $user = $this->member();

        $response = $this->connectWithCode($user, 'facebook')->assertOk();

        $response->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.platform', 'facebook')
            ->assertJsonPath('data.0.name', 'Acme Coffee')
            ->assertJsonPath('data.0.username', 'acmecoffee')
            ->assertJsonPath('data.0.avatar_url', 'https://img.test/111.jpg')
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.capabilities.can_publish_video', true)
            ->assertJsonPath('data.0.profile.followers', 1280)
            ->assertJsonPath('data.0.permissions.missing', [])
            ->assertJsonPath('data.0.token.present', true)
            ->assertJsonPath('data.0.token.expires_at', null)
            ->assertJsonPath('data.1.name', 'Acme Roasters')
            ->assertJsonPath('data.1.capabilities.can_publish_video', false);

        // Page 333 has no page token, so it cannot be posted to and is skipped.
        $this->assertSame(2, SocialAccount::query()->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'platform' => 'facebook_page', 'external_id' => '111', 'provider' => 'meta']);
    }

    public function test_the_authorization_code_is_exchanged_with_the_registered_redirect_uri(): void
    {
        $this->fakeFacebook();

        $this->connectWithCode($this->member(), 'facebook', 'the-code')->assertOk();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/oauth/access_token')
            && $request['code'] === 'the-code'
            && $request['redirect_uri'] === 'http://localhost:8080/connections/callback/facebook'
            && $request['client_secret'] === 'fb-app-secret');
        Http::assertSent(fn (Request $request) => ($request['grant_type'] ?? null) === 'fb_exchange_token'
            && $request['fb_exchange_token'] === 'short-user-token');
    }

    public function test_page_tokens_are_encrypted_and_never_returned(): void
    {
        $this->fakeFacebook();

        $response = $this->connectWithCode($this->member(), 'facebook')->assertOk();

        $this->assertStringNotContainsString('page-token', $response->getContent());
        $this->assertStringNotContainsString('user-token', $response->getContent());

        $raw = DB::table('social_tokens')->orderBy('id')->value('access_token');
        $this->assertNotSame('page-token-111', $raw);
        $this->assertSame('page-token-111', Crypt::decryptString($raw));
        $this->assertSame('page-token-111', SocialToken::query()->orderBy('id')->firstOrFail()->access_token);
    }

    public function test_the_audit_log_records_the_connection_without_secrets(): void
    {
        $this->fakeFacebook();
        $user = $this->member();

        $this->connectWithCode($user, 'facebook')->assertOk();

        $this->assertSame(2, DB::table('audit_logs')->where('user_id', $user->id)->where('action', 'social.account.connected')->count());

        $dump = json_encode(DB::table('audit_logs')->get());
        $this->assertStringNotContainsString('page-token', $dump);
        $this->assertStringNotContainsString('auth-code', $dump);
    }

    public function test_connecting_again_updates_the_same_accounts(): void
    {
        $this->fakeFacebook();
        $user = $this->member();

        $first = $this->connectWithCode($user, 'facebook')->json('data.0.id');
        $second = $this->connectWithCode($user, 'facebook')->json('data.0.id');

        $this->assertSame($first, $second);
        $this->assertSame(2, SocialAccount::query()->count());
        $this->assertSame(2, SocialToken::query()->count());
    }

    public function test_reconnecting_a_disconnected_page_restores_it(): void
    {
        $this->fakeFacebook();
        $user = $this->member();

        $id = $this->connectWithCode($user, 'facebook')->json('data.0.id');
        $this->actingAs($user)->deleteJson("/api/v1/social/accounts/{$id}")->assertNoContent();
        $this->assertSame(1, SocialAccount::onlyTrashed()->count());

        $again = $this->connectWithCode($user, 'facebook')->json('data.0.id');

        $this->assertSame($id, $again);
        $this->assertSame(0, SocialAccount::onlyTrashed()->count());
    }

    public function test_it_refuses_a_connection_missing_required_permissions(): void
    {
        $this->fakeFacebook(['graph.facebook.com/*/me/permissions*' => Http::response(['data' => [
            ['permission' => 'pages_show_list', 'status' => 'granted'],
            ['permission' => 'pages_read_engagement', 'status' => 'granted'],
            ['permission' => 'pages_manage_posts', 'status' => 'declined'],
        ]])]);

        $this->connectWithCode($this->member(), 'facebook')
            ->assertStatus(422)
            ->assertJsonPath('code', 'missing_permissions');

        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertDatabaseCount('social_tokens', 0);
    }

    public function test_it_explains_when_no_page_was_shared(): void
    {
        $this->fakeFacebook(['graph.facebook.com/*/me/accounts*' => Http::response(['data' => []])]);

        $this->connectWithCode($this->member(), 'facebook')
            ->assertStatus(422)
            ->assertJsonPath('code', 'account_not_eligible');
    }

    public function test_a_rejected_code_is_reported_as_a_failed_sign_in(): void
    {
        $this->fakeFacebook(['graph.facebook.com/*/oauth/access_token*' => Http::response([
            'error' => ['message' => 'Invalid verification code format.', 'type' => 'OAuthException', 'code' => 100],
        ], 400)]);

        $this->connectWithCode($this->member(), 'facebook')
            ->assertStatus(400)
            ->assertJsonPath('code', 'authorization_failed');

        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_a_meta_outage_is_reported_without_provider_details(): void
    {
        $this->fakeFacebook(['graph.facebook.com/*/oauth/access_token*' => Http::response(['error' => ['message' => 'internal secret detail', 'code' => 2]], 503)]);

        $response = $this->connectWithCode($this->member(), 'facebook')
            ->assertStatus(502)
            ->assertJsonPath('code', 'provider_unavailable');

        $this->assertStringNotContainsString('internal secret detail', $response->getContent());
    }
}
