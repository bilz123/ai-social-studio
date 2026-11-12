<?php

namespace Tests\Feature\Social;

use App\Models\SocialToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class InstagramConnectionTest extends SocialTestCase
{
    /** @return array<string, mixed> */
    private function profile(string $type = 'BUSINESS'): array
    {
        return [
            'user_id' => '17841400000000001',
            'id' => '2400000000000001',
            'username' => 'acme.coffee',
            'name' => 'Acme Coffee',
            'account_type' => $type,
            'profile_picture_url' => 'https://img.test/ig.jpg',
            'followers_count' => 5400,
            'follows_count' => 120,
            'media_count' => 88,
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    private function fakeInstagram(array $overrides = []): void
    {
        Http::fake(array_merge([
            'api.instagram.com/oauth/access_token*' => Http::response([
                'access_token' => 'short-ig-token',
                'user_id' => '17841400000000001',
                'permissions' => 'instagram_business_basic,instagram_business_content_publish',
            ]),
            'graph.instagram.com/access_token*' => Http::response(['access_token' => 'long-ig-token', 'token_type' => 'bearer', 'expires_in' => 5183944]),
            'graph.instagram.com/*/me*' => Http::response($this->profile()),
        ], $overrides));
    }

    public function test_it_connects_a_business_account(): void
    {
        $this->freezeTime();
        $this->fakeInstagram();
        $user = $this->member();

        $this->connectWithCode($user, 'instagram')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.platform', 'instagram')
            ->assertJsonPath('data.0.provider', 'instagram')
            ->assertJsonPath('data.0.name', 'Acme Coffee')
            ->assertJsonPath('data.0.username', 'acme.coffee')
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.profile.account_type', 'BUSINESS')
            ->assertJsonPath('data.0.profile.followers', 5400)
            ->assertJsonPath('data.0.capabilities.can_publish_reels', true)
            ->assertJsonPath('data.0.permissions.missing', [])
            ->assertJsonPath('data.0.token.expires_at', now()->addSeconds(5183944)->toIso8601ZuluString());

        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'platform' => 'instagram_business', 'external_id' => '17841400000000001']);
    }

    public function test_it_connects_a_creator_account(): void
    {
        $this->fakeInstagram(['graph.instagram.com/*/me*' => Http::response($this->profile('MEDIA_CREATOR'))]);

        $this->connectWithCode($this->member(), 'instagram')->assertOk()->assertJsonPath('data.0.profile.account_type', 'MEDIA_CREATOR');
    }

    public function test_it_exchanges_the_code_then_upgrades_to_a_long_lived_token(): void
    {
        $this->fakeInstagram();

        // Instagram appends "#_" to the code on the redirect URL; it must be removed.
        $this->connectWithCode($this->member(), 'instagram', 'ig-code#_')->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.instagram.com/oauth/access_token'
            && $request['code'] === 'ig-code'
            && $request['grant_type'] === 'authorization_code'
            && $request['redirect_uri'] === 'http://localhost:8080/connections/callback/instagram'
            && $request['client_id'] === 'ig-app-id');

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            return str_starts_with($request->url(), 'https://graph.instagram.com/access_token')
                && $query['grant_type'] === 'ig_exchange_token'
                && $query['access_token'] === 'short-ig-token'
                && $query['client_secret'] === 'ig-app-secret';
        });

        // The profile is read with the long-lived token, sent as a bearer header (not in the URL).
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/me?')
            && $request->hasHeader('Authorization', 'Bearer long-ig-token'));
    }

    public function test_it_accepts_the_wrapped_token_response_format(): void
    {
        $this->fakeInstagram(['api.instagram.com/oauth/access_token*' => Http::response(['data' => [[
            'access_token' => 'short-ig-token',
            'user_id' => '17841400000000001',
            'permissions' => 'instagram_business_basic,instagram_business_content_publish',
        ]]])]);

        $this->connectWithCode($this->member(), 'instagram')->assertOk();
    }

    public function test_the_token_is_encrypted_and_never_returned(): void
    {
        $this->fakeInstagram();

        $response = $this->connectWithCode($this->member(), 'instagram')->assertOk();

        $this->assertStringNotContainsString('ig-token', $response->getContent());

        $raw = DB::table('social_tokens')->value('access_token');
        $this->assertNotSame('long-ig-token', $raw);
        $this->assertSame('long-ig-token', Crypt::decryptString($raw));
        $this->assertSame(['instagram_business_basic', 'instagram_business_content_publish'], SocialToken::query()->firstOrFail()->scopes);
    }

    public function test_it_refuses_personal_accounts_with_instructions(): void
    {
        $this->fakeInstagram(['graph.instagram.com/*/me*' => Http::response($this->profile('PERSONAL'))]);

        $response = $this->connectWithCode($this->member(), 'instagram')
            ->assertStatus(422)
            ->assertJsonPath('code', 'account_not_eligible');

        $this->assertStringContainsString('Business or Creator', $response->json('detail'));
        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertDatabaseCount('social_tokens', 0);
    }

    public function test_it_refuses_a_connection_without_publishing_permission(): void
    {
        $this->fakeInstagram(['api.instagram.com/oauth/access_token*' => Http::response([
            'access_token' => 'short-ig-token',
            'user_id' => '17841400000000001',
            'permissions' => 'instagram_business_basic',
        ])]);

        $this->connectWithCode($this->member(), 'instagram')
            ->assertStatus(422)
            ->assertJsonPath('code', 'missing_permissions');

        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_a_rejected_code_is_reported_as_a_failed_sign_in(): void
    {
        $this->fakeInstagram(['api.instagram.com/oauth/access_token*' => Http::response([
            'error_type' => 'OAuthException',
            'code' => 400,
            'error_message' => 'Invalid authorization code',
        ], 400)]);

        $this->connectWithCode($this->member(), 'instagram')
            ->assertStatus(400)
            ->assertJsonPath('code', 'authorization_failed');
    }
}
