<?php

namespace Tests\Feature\Social;

use App\Enums\SocialAccountStatus;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

/** "Check connection": token renewal, permission tracking and reconnect detection. */
class ConnectionHealthTest extends SocialTestCase
{
    private const YT_SCOPES = ['https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly'];

    private const IG_SCOPES = ['instagram_business_basic', 'instagram_business_content_publish'];

    private const FB_SCOPES = ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'];

    /** @return array<string, mixed> */
    private function igProfile(): array
    {
        return ['user_id' => '1784', 'username' => 'acme', 'name' => 'Acme Renamed', 'account_type' => 'BUSINESS', 'followers_count' => 10, 'follows_count' => 1, 'media_count' => 2];
    }

    /** @return array<string, mixed> */
    private function ytChannel(string $id = 'UC1'): array
    {
        return ['id' => $id, 'snippet' => ['title' => 'Chan', 'customUrl' => '@chan'], 'statistics' => ['subscriberCount' => '7', 'videoCount' => '1', 'viewCount' => '9']];
    }

    private function check(SocialAccount $account): TestResponse
    {
        return $this->actingAs($account->user()->firstOrFail())->postJson("/api/v1/social/accounts/{$account->ulid}/check");
    }

    // --- Facebook ----------------------------------------------------------------------------

    public function test_a_valid_facebook_page_is_refreshed_from_the_provider(): void
    {
        $account = SocialAccount::factory()->for($this->member())->facebook()
            ->withToken(['access_token' => 'page-token-111', 'refresh_token' => null, 'expires_at' => null, 'scopes' => self::FB_SCOPES])
            ->create(['external_id' => '111', 'name' => 'Old Name']);
        Http::fake([
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'expires_at' => 0, 'scopes' => self::FB_SCOPES]]),
            'graph.facebook.com/*/111?*' => Http::response(['id' => '111', 'name' => 'Acme Coffee Co', 'username' => 'acme', 'category' => 'Cafe', 'fan_count' => 1500, 'picture' => ['data' => ['url' => 'https://img.test/new.jpg']]]),
        ]);

        $this->check($account)
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Coffee Co')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.profile.followers', 1500)
            ->assertJsonPath('data.avatar_url', 'https://img.test/new.jpg');

        $this->assertNotNull($account->fresh()->last_checked_at);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/debug_token')
            && $this->queryOf($request)['input_token'] === 'page-token-111'
            && $this->queryOf($request)['access_token'] === 'fb-app-id|fb-app-secret');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/111?') && $request->hasHeader('Authorization', 'Bearer page-token-111'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'social.account.checked', 'subject_id' => $account->ulid]);
    }

    public function test_revoked_permissions_put_the_account_in_restricted_state(): void
    {
        $account = SocialAccount::factory()->for($this->member())->facebook()
            ->withToken(['access_token' => 'page-token-111', 'scopes' => self::FB_SCOPES, 'expires_at' => null])
            ->create(['external_id' => '111']);
        Http::fake([
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'scopes' => ['pages_show_list', 'pages_read_engagement']]]),
            'graph.facebook.com/*/111?*' => Http::response(['id' => '111', 'name' => 'Acme']),
        ]);

        $this->check($account)
            ->assertOk()
            ->assertJsonPath('data.status', 'restricted')
            ->assertJsonPath('data.permissions.missing', ['pages_manage_posts']);

        $this->assertStringContainsString('pages_manage_posts', $account->fresh()->status_reason);
    }

    public function test_an_invalid_facebook_token_asks_the_user_to_reconnect(): void
    {
        $account = SocialAccount::factory()->for($this->member())->facebook()->withToken(['expires_at' => null])->create(['external_id' => '111']);
        Http::fake(['graph.facebook.com/*/debug_token*' => Http::response(['data' => ['is_valid' => false]])]);

        $this->check($account)->assertStatus(409)->assertJsonPath('code', 'token_rejected');

        $fresh = $account->fresh();
        $this->assertSame(SocialAccountStatus::NeedsReconnect, $fresh->status);
        $this->assertNotEmpty($fresh->status_reason);
        $this->assertDatabaseHas('audit_logs', ['action' => 'social.account.needs_reconnect', 'subject_id' => $account->ulid]);
    }

    public function test_a_healthy_connection_recovers_from_a_restricted_state(): void
    {
        $account = SocialAccount::factory()->for($this->member())->facebook()->status(SocialAccountStatus::Restricted)
            ->withToken(['scopes' => self::FB_SCOPES, 'expires_at' => null])->create(['external_id' => '111', 'status_reason' => 'Missing permissions: x.']);
        Http::fake([
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'scopes' => self::FB_SCOPES]]),
            'graph.facebook.com/*/111?*' => Http::response(['id' => '111', 'name' => 'Acme']),
        ]);

        $this->check($account)->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.status_reason', null);
    }

    // --- Instagram ---------------------------------------------------------------------------

    public function test_an_instagram_token_close_to_expiry_is_renewed(): void
    {
        $this->freezeTime();
        $account = SocialAccount::factory()->for($this->member())->instagram()
            ->withToken(['access_token' => 'old-ig', 'refresh_token' => null, 'expires_at' => now()->addDays(3), 'scopes' => self::IG_SCOPES])
            ->create(['external_id' => '1784']);
        Http::fake([
            'graph.instagram.com/refresh_access_token*' => Http::response(['access_token' => 'new-ig', 'token_type' => 'bearer', 'expires_in' => 5183999]),
            'graph.instagram.com/*/me*' => Http::response($this->igProfile()),
        ]);

        $this->check($account)
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Renamed')
            ->assertJsonPath('data.token.expires_at', now()->addSeconds(5183999)->toIso8601ZuluString());

        $token = SocialToken::query()->firstOrFail();
        $this->assertSame('new-ig', $token->access_token);
        $this->assertSame(self::IG_SCOPES, $token->scopes);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            return str_starts_with($request->url(), 'https://graph.instagram.com/refresh_access_token')
                && $query['grant_type'] === 'ig_refresh_token' && $query['access_token'] === 'old-ig';
        });
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/me?') && $request->hasHeader('Authorization', 'Bearer new-ig'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'social.token.refreshed', 'subject_id' => $account->ulid]);
    }

    public function test_a_fresh_instagram_token_is_not_renewed(): void
    {
        $account = SocialAccount::factory()->for($this->member())->instagram()
            ->withToken(['access_token' => 'ig-ok', 'refresh_token' => null, 'expires_at' => now()->addDays(50), 'scopes' => self::IG_SCOPES])
            ->create(['external_id' => '1784']);
        Http::fake(['graph.instagram.com/*/me*' => Http::response($this->igProfile())]);

        $this->check($account)->assertOk();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'refresh_access_token'));
        $this->assertSame('ig-ok', SocialToken::query()->firstOrFail()->access_token);
    }

    public function test_an_expired_instagram_token_that_cannot_be_renewed_needs_reconnect(): void
    {
        $account = SocialAccount::factory()->for($this->member())->instagram()
            ->withToken(['refresh_token' => null, 'expires_at' => now()->subDay(), 'scopes' => self::IG_SCOPES])->create(['external_id' => '1784']);
        Http::fake(['graph.instagram.com/refresh_access_token*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'type' => 'OAuthException', 'code' => 190]], 400)]);

        $this->check($account)->assertStatus(409)->assertJsonPath('code', 'token_rejected');

        $this->assertSame(SocialAccountStatus::NeedsReconnect, $account->fresh()->status);
        $this->assertSame('token_rejected', SocialToken::query()->firstOrFail()->last_error);
    }

    // --- YouTube -----------------------------------------------------------------------------

    public function test_an_expired_youtube_access_token_is_renewed_with_the_refresh_token(): void
    {
        $this->freezeTime();
        $account = SocialAccount::factory()->for($this->member())->youtube()
            ->withToken(['access_token' => 'old-access', 'refresh_token' => 'rt-1', 'expires_at' => now()->subMinute(), 'scopes' => self::YT_SCOPES])
            ->create(['external_id' => 'UC1']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-access', 'expires_in' => 3599, 'scope' => implode(' ', self::YT_SCOPES), 'token_type' => 'Bearer']),
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [$this->ytChannel()]]),
        ]);

        $this->check($account)->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.name', 'Chan');

        $token = SocialToken::query()->firstOrFail();
        $this->assertSame('new-access', $token->access_token);
        $this->assertSame('rt-1', $token->refresh_token, 'Google does not return a new refresh token; the stored one must be kept.');
        $this->assertSame(now()->addSeconds(3599)->timestamp, $token->expires_at->timestamp);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'rt-1');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/youtube/v3/channels') && $request->hasHeader('Authorization', 'Bearer new-access'));
    }

    public function test_a_youtube_token_with_plenty_of_time_left_is_not_renewed(): void
    {
        $account = SocialAccount::factory()->for($this->member())->youtube()
            ->withToken(['access_token' => 'still-good', 'refresh_token' => 'rt-1', 'expires_at' => now()->addMinutes(30), 'scopes' => self::YT_SCOPES])
            ->create(['external_id' => 'UC1']);
        Http::fake(['www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [$this->ytChannel()]])]);

        $this->check($account)->assertOk();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com'));
    }

    public function test_a_revoked_google_grant_needs_reconnect(): void
    {
        $account = SocialAccount::factory()->for($this->member())->youtube()
            ->withToken(['refresh_token' => 'rt-1', 'expires_at' => now()->subMinute(), 'scopes' => self::YT_SCOPES])->create(['external_id' => 'UC1']);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);

        $this->check($account)->assertStatus(409)->assertJsonPath('code', 'token_rejected');

        $this->assertSame(SocialAccountStatus::NeedsReconnect, $account->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'social.account.needs_reconnect', 'subject_id' => $account->ulid]);
    }

    public function test_a_channel_the_google_account_no_longer_has_needs_reconnect(): void
    {
        $account = SocialAccount::factory()->for($this->member())->youtube()
            ->withToken(['refresh_token' => 'rt-1', 'expires_at' => now()->addHour(), 'scopes' => self::YT_SCOPES])->create(['external_id' => 'UC1']);
        Http::fake(['www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [$this->ytChannel('UCother')]])]);

        $this->check($account)->assertStatus(409)->assertJsonPath('code', 'account_unavailable');

        $this->assertSame(SocialAccountStatus::NeedsReconnect, $account->fresh()->status);
    }

    // --- Temporary failures ------------------------------------------------------------------

    public function test_a_provider_outage_does_not_disconnect_the_account(): void
    {
        $account = SocialAccount::factory()->for($this->member())->youtube()
            ->withToken(['expires_at' => now()->addHour(), 'scopes' => self::YT_SCOPES])->create(['external_id' => 'UC1']);
        Http::fake(['www.googleapis.com/youtube/v3/channels*' => Http::response(['error' => ['code' => 503, 'message' => 'Backend Error']], 503)]);

        $this->check($account)->assertStatus(502)->assertJsonPath('code', 'provider_unavailable');

        $this->assertSame(SocialAccountStatus::Active, $account->fresh()->status);
        $this->assertSame('provider_unavailable', SocialToken::query()->firstOrFail()->last_error);
    }

    public function test_rate_limiting_by_the_provider_is_reported_as_such(): void
    {
        $account = SocialAccount::factory()->for($this->member())->youtube()
            ->withToken(['expires_at' => now()->addHour(), 'scopes' => self::YT_SCOPES])->create(['external_id' => 'UC1']);
        Http::fake(['www.googleapis.com/youtube/v3/channels*' => Http::response(['error' => ['code' => 403, 'message' => 'quota', 'errors' => [['reason' => 'quotaExceeded']]]], 403)]);

        $this->check($account)->assertStatus(429)->assertJsonPath('code', 'provider_rate_limited');

        $this->assertSame(SocialAccountStatus::Active, $account->fresh()->status);
    }

    public function test_network_failures_are_retried_then_reported(): void
    {
        $account = SocialAccount::factory()->for($this->member())->youtube()
            ->withToken(['expires_at' => now()->addHour(), 'scopes' => self::YT_SCOPES])->create(['external_id' => 'UC1']);
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->check($account)->assertStatus(502)->assertJsonPath('code', 'provider_unavailable');

        Sleep::assertSleptTimes(2);
        $this->assertSame(SocialAccountStatus::Active, $account->fresh()->status);
    }
}
