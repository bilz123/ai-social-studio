<?php

namespace Tests\Feature\Social;

use App\Models\OAuthState;
use App\Models\SocialToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class YouTubeConnectionTest extends SocialTestCase
{
    private const UPLOAD = 'https://www.googleapis.com/auth/youtube.upload';

    private const READONLY = 'https://www.googleapis.com/auth/youtube.readonly';

    /** @return array<string, mixed> */
    private function channel(): array
    {
        return [
            'id' => 'UCabc123',
            'snippet' => [
                'title' => 'Acme Coffee',
                'customUrl' => '@acmecoffee',
                'country' => 'US',
                'thumbnails' => ['default' => ['url' => 'https://img.test/yt-default.jpg'], 'medium' => ['url' => 'https://img.test/yt.jpg']],
            ],
            'statistics' => ['subscriberCount' => '2300', 'videoCount' => '41', 'viewCount' => '99000', 'hiddenSubscriberCount' => false],
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    private function fakeGoogle(array $overrides = []): void
    {
        Http::fake(array_merge([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.access',
                'expires_in' => 3599,
                'refresh_token' => '1//refresh',
                'scope' => self::UPLOAD.' '.self::READONLY,
                'token_type' => 'Bearer',
            ]),
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [$this->channel()]]),
        ], $overrides));
    }

    public function test_it_connects_the_users_channel(): void
    {
        $this->freezeTime();
        $this->fakeGoogle();
        $user = $this->member();

        $this->connectWithCode($user, 'youtube')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.platform', 'youtube')
            ->assertJsonPath('data.0.provider', 'google')
            ->assertJsonPath('data.0.name', 'Acme Coffee')
            ->assertJsonPath('data.0.username', '@acmecoffee')
            ->assertJsonPath('data.0.avatar_url', 'https://img.test/yt.jpg')
            ->assertJsonPath('data.0.profile.subscribers', 2300)
            ->assertJsonPath('data.0.profile.videos', 41)
            ->assertJsonPath('data.0.capabilities.can_upload_video', true)
            ->assertJsonPath('data.0.permissions.missing', [])
            ->assertJsonPath('data.0.token.has_refresh_token', true)
            ->assertJsonPath('data.0.token.expires_at', now()->addSeconds(3599)->toIso8601ZuluString());

        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'platform' => 'youtube_channel', 'external_id' => 'UCabc123']);
    }

    public function test_the_code_is_exchanged_with_the_pkce_verifier_and_the_channel_is_read_with_a_bearer_token(): void
    {
        $this->fakeGoogle();
        $user = $this->member();

        [, $query] = $this->startConnect($user, 'youtube');
        $verifier = OAuthState::query()->firstOrFail()->code_verifier;

        $this->callback($user, 'youtube', ['code' => 'g-code', 'state' => $query['state']])->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['code'] === 'g-code'
            && $request['code_verifier'] === $verifier
            && $request['grant_type'] === 'authorization_code'
            && $request['redirect_uri'] === 'http://localhost:8080/connections/callback/youtube');

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://www.googleapis.com/youtube/v3/channels')
            && $this->queryOf($request)['mine'] === 'true'
            && $request->hasHeader('Authorization', 'Bearer ya29.access'));
    }

    public function test_tokens_are_encrypted_and_never_returned(): void
    {
        $this->fakeGoogle();

        $response = $this->connectWithCode($this->member(), 'youtube')->assertOk();

        $this->assertStringNotContainsString('ya29', $response->getContent());
        $this->assertStringNotContainsString('1//refresh', $response->getContent());

        $row = DB::table('social_tokens')->first();
        $this->assertSame('ya29.access', Crypt::decryptString($row->access_token));
        $this->assertSame('1//refresh', Crypt::decryptString($row->refresh_token));
        $this->assertNotSame('1//refresh', $row->refresh_token);
        $this->assertSame('1//refresh', SocialToken::query()->firstOrFail()->refresh_token);
    }

    public function test_it_requires_offline_access(): void
    {
        $this->fakeGoogle(['oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'ya29.access',
            'expires_in' => 3599,
            'scope' => self::UPLOAD.' '.self::READONLY,
        ])]);

        $this->connectWithCode($this->member(), 'youtube')
            ->assertStatus(400)
            ->assertJsonPath('code', 'authorization_failed');

        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_it_refuses_when_the_user_unticked_the_upload_permission(): void
    {
        $this->fakeGoogle(['oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'ya29.access',
            'refresh_token' => '1//refresh',
            'expires_in' => 3599,
            'scope' => self::READONLY,
        ])]);

        $response = $this->connectWithCode($this->member(), 'youtube')
            ->assertStatus(422)
            ->assertJsonPath('code', 'missing_permissions');

        $this->assertStringContainsString('youtube.upload', $response->json('detail'));
        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertDatabaseCount('social_tokens', 0);
    }

    public function test_it_explains_when_the_google_account_has_no_channel(): void
    {
        $this->fakeGoogle(['www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => []])]);

        $this->connectWithCode($this->member(), 'youtube')
            ->assertStatus(422)
            ->assertJsonPath('code', 'account_not_eligible');

        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_a_used_or_invalid_code_is_reported_as_a_failed_sign_in(): void
    {
        $this->fakeGoogle(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Bad Request'], 400)]);

        $this->connectWithCode($this->member(), 'youtube')
            ->assertStatus(400)
            ->assertJsonPath('code', 'authorization_failed');
    }

    public function test_rejected_application_credentials_are_reported_as_a_server_problem(): void
    {
        $this->fakeGoogle(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_client', 'error_description' => 'Unauthorized'], 401)]);

        $this->connectWithCode($this->member(), 'youtube')
            ->assertStatus(502)
            ->assertJsonPath('code', 'provider_error');
    }
}
