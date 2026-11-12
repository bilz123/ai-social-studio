<?php

namespace Tests\Feature\Social;

use App\Enums\SocialAccountStatus;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class AccountManagementTest extends SocialTestCase
{
    public function test_the_list_contains_only_the_users_own_accounts_and_no_secrets(): void
    {
        $user = $this->member();
        $mine = SocialAccount::factory()->for($user)->facebook()->withToken(['access_token' => 'secret-access', 'refresh_token' => 'secret-refresh'])->create(['name' => 'Mine']);
        SocialAccount::factory()->for($this->member())->facebook()->withToken()->create(['name' => 'Theirs']);

        $response = $this->actingAs($user)->getJson('/api/v1/social/accounts')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->ulid)
            ->assertJsonPath('data.0.name', 'Mine')
            ->assertJsonPath('data.0.token.present', true)
            ->assertJsonPath('data.0.token.has_refresh_token', true);

        $this->assertStringNotContainsString('secret-', $response->getContent());
        $this->assertArrayNotHasKey('access_token', $response->json('data.0.token'));
    }

    public function test_it_reports_missing_permissions_per_account(): void
    {
        $user = $this->member();
        SocialAccount::factory()->for($user)->facebook()->withToken(['scopes' => ['pages_show_list']])->create();

        $this->actingAs($user)->getJson('/api/v1/social/accounts')
            ->assertJsonPath('data.0.permissions.granted', ['pages_show_list'])
            ->assertJsonPath('data.0.permissions.missing', ['pages_read_engagement', 'pages_manage_posts']);
    }

    public function test_publishing_can_be_switched_off_without_disconnecting(): void
    {
        $user = $this->member();
        $account = SocialAccount::factory()->for($user)->facebook()->withToken()->create();

        $this->actingAs($user)->patchJson("/api/v1/social/accounts/{$account->ulid}", ['is_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.is_enabled', false);

        $this->assertFalse($account->fresh()->is_enabled);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'social.account.updated', 'subject_id' => $account->ulid]);
    }

    public function test_the_enable_switch_must_be_a_boolean(): void
    {
        $user = $this->member();
        $account = SocialAccount::factory()->for($user)->facebook()->create();

        $this->actingAs($user)->patchJson("/api/v1/social/accounts/{$account->ulid}", [])
            ->assertStatus(422)->assertJsonValidationErrors(['is_enabled'], 'errors');
    }

    public function test_other_users_accounts_cannot_be_changed_checked_or_disconnected(): void
    {
        Http::fake();
        $owner = $this->member();
        $account = SocialAccount::factory()->for($owner)->facebook()->withToken()->create();
        $intruder = $this->member();

        $this->actingAs($intruder)->patchJson("/api/v1/social/accounts/{$account->ulid}", ['is_enabled' => false])->assertForbidden();
        $this->actingAs($intruder)->postJson("/api/v1/social/accounts/{$account->ulid}/check")->assertForbidden();
        $this->actingAs($intruder)->deleteJson("/api/v1/social/accounts/{$account->ulid}")->assertForbidden();

        $this->assertDatabaseHas('social_accounts', ['id' => $account->id, 'deleted_at' => null]);
        $this->assertDatabaseCount('social_tokens', 1);
        Http::assertNothingSent();
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/v1/social/accounts')->assertForbidden();
    }

    public function test_disconnecting_deletes_credentials_and_hides_the_account(): void
    {
        Http::fake();
        $user = $this->member();
        $account = SocialAccount::factory()->for($user)->facebook()->withToken()->create();

        $this->actingAs($user)->deleteJson("/api/v1/social/accounts/{$account->ulid}")->assertNoContent();

        $this->assertSoftDeleted('social_accounts', ['id' => $account->id]);
        $this->assertDatabaseCount('social_tokens', 0);
        $this->assertSame(SocialAccountStatus::Revoked, SocialAccount::withTrashed()->findOrFail($account->id)->status);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'social.account.disconnected', 'subject_id' => $account->ulid]);
        $this->actingAs($user)->getJson('/api/v1/social/accounts')->assertJsonCount(0, 'data');
        // Facebook has no revocation call for page tokens.
        Http::assertNothingSent();
    }

    public function test_disconnecting_a_youtube_channel_revokes_the_google_grant(): void
    {
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response([], 200)]);
        $user = $this->member();
        $account = SocialAccount::factory()->for($user)->youtube()->withToken(['access_token' => 'ya29.a', 'refresh_token' => '1//rt'])->create();

        $this->actingAs($user)->deleteJson("/api/v1/social/accounts/{$account->ulid}")->assertNoContent();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/revoke' && $request['token'] === '1//rt');
        $this->assertDatabaseCount('social_tokens', 0);
    }

    public function test_a_failed_revocation_does_not_block_disconnecting(): void
    {
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response(['error' => 'invalid_token'], 400)]);
        $user = $this->member();
        $account = SocialAccount::factory()->for($user)->youtube()->withToken()->create();

        $this->actingAs($user)->deleteJson("/api/v1/social/accounts/{$account->ulid}")->assertNoContent();

        $this->assertSoftDeleted('social_accounts', ['id' => $account->id]);
        $this->assertDatabaseCount('social_tokens', 0);
    }

    public function test_deleting_the_user_account_revokes_social_grants_first(): void
    {
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response([], 200)]);
        $user = $this->member();
        SocialAccount::factory()->for($user)->youtube()->withToken(['refresh_token' => '1//gone'])->create();

        $this->actingAs($user)->deleteJson('/api/v1/account', ['password' => 'Str0ng-Passw0rd!'])->assertNoContent();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/revoke' && $request['token'] === '1//gone');
        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertSame(0, SocialToken::query()->count());
    }
}
