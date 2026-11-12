<?php

namespace Tests\Feature\Social;

use App\Enums\SocialAccountStatus;
use App\Models\OAuthState;
use App\Models\SocialAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

class ConsoleCommandsTest extends SocialTestCase
{
    public function test_refresh_command_checks_only_accounts_that_are_due_and_usable(): void
    {
        $user = $this->member();
        $stale = SocialAccount::factory()->for($user)->facebook()
            ->withToken(['access_token' => 'page-token-1', 'refresh_token' => null, 'expires_at' => null, 'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts']])
            ->create(['external_id' => '111', 'last_checked_at' => now()->subDays(2)]);
        $recent = SocialAccount::factory()->for($user)->facebook()->withToken()->create(['last_checked_at' => now()->subHour()]);
        $broken = SocialAccount::factory()->for($user)->facebook()->status(SocialAccountStatus::NeedsReconnect)->withToken()->create(['last_checked_at' => null]);
        Http::fake([
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts']]]),
            'graph.facebook.com/*/111?*' => Http::response(['id' => '111', 'name' => 'Checked Page']),
        ]);

        $this->assertSame(0, Artisan::call('social:refresh-tokens'));

        $this->assertStringContainsString('Checked 1;', Artisan::output());
        $this->assertSame('Checked Page', $stale->fresh()->name);
        $this->assertNull($broken->fresh()->last_checked_at);
        $this->assertSame($recent->name, $recent->fresh()->name);
    }

    public function test_refresh_command_marks_revoked_connections_and_survives_outages(): void
    {
        $user = $this->member();
        $revoked = SocialAccount::factory()->for($user)->facebook()->withToken(['access_token' => 'revoked-token', 'expires_at' => null])->create(['external_id' => '111']);
        $outage = SocialAccount::factory()->for($user)->facebook()->withToken(['expires_at' => null])->create(['external_id' => '222']);
        Http::fake([
            'graph.facebook.com/*/debug_token*' => fn (Request $request) => str_contains($request->url(), 'input_token=revoked-token')
                ? Http::response(['data' => ['is_valid' => false]])
                : Http::response(['error' => ['message' => 'down', 'code' => 2]], 503),
        ]);

        $this->assertSame(0, Artisan::call('social:refresh-tokens', ['--all' => true]));

        $this->assertStringContainsString('need reconnect 1', Artisan::output());
        $this->assertSame(SocialAccountStatus::NeedsReconnect, $revoked->fresh()->status);
        $this->assertSame(SocialAccountStatus::Active, $outage->fresh()->status);
    }

    public function test_check_config_lists_platforms_and_redirect_urls(): void
    {
        config()->set('social.providers.youtube.client_secret', null);

        $this->assertSame(0, Artisan::call('social:check-config'));

        $output = Artisan::output();
        $this->assertStringContainsString('http://localhost:8080/connections/callback/facebook', $output);
        $this->assertStringContainsString('http://localhost:8080/connections/callback/youtube', $output);
        $this->assertStringContainsString('missing GOOGLE_CLIENT_SECRET', $output);
        $this->assertStringContainsString('configured', $output);
    }

    public function test_check_config_can_fail_the_build_when_something_is_missing(): void
    {
        config()->set('social.providers.instagram.client_id', '');

        $this->assertSame(1, Artisan::call('social:check-config', ['--strict' => true]));
    }

    public function test_expired_oauth_states_are_pruned(): void
    {
        $user = $this->member();

        foreach (['old' => now()->subDays(2), 'recent' => now()->subMinutes(5)] as $name => $expiresAt) {
            (new OAuthState)->forceFill([
                'user_id' => $user->id,
                'state_hash' => hash('sha256', $name),
                'platform' => 'facebook_page',
                'expires_at' => $expiresAt,
            ])->save();
        }

        Artisan::call('model:prune', ['--model' => [OAuthState::class]]);

        $this->assertDatabaseMissing('oauth_states', ['state_hash' => hash('sha256', 'old')]);
        $this->assertDatabaseHas('oauth_states', ['state_hash' => hash('sha256', 'recent')]);
    }
}
