<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The SPA link inside the verification email, as the query the SPA forwards to the API.
     *
     * @return array{id: string, hash: string, expires: string, signature: string}
     */
    private function linkFor(User $user): array
    {
        $url = (new VerifyEmailNotification)->toMail($user)->actionUrl;

        $this->assertStringStartsWith('http://localhost:8080/verify-email?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        /** @var array{id: string, hash: string, expires: string, signature: string} $query */
        return $query;
    }

    /** @param array<string, string> $query */
    private function verifyUrl(array $query): string
    {
        return '/api/v1/auth/verify-email/'.$query['id'].'/'.$query['hash']
            .'?'.http_build_query(['expires' => $query['expires'], 'signature' => $query['signature']]);
    }

    public function test_the_emailed_link_verifies_the_address(): void
    {
        $user = User::factory()->unverified()->create();

        // No session needed: the signature proves the link came from us.
        $this->getJson($this->verifyUrl($this->linkFor($user)))
            ->assertOk()
            ->assertJsonPath('data.verified', true);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'auth.email_verified']);
    }

    public function test_using_the_link_twice_is_harmless(): void
    {
        $user = User::factory()->unverified()->create();
        $url = $this->verifyUrl($this->linkFor($user));

        $this->getJson($url)->assertOk();
        $this->getJson($url)->assertOk();

        $this->assertSame(1, $user->auditLogs()->where('action', 'auth.email_verified')->count());
    }

    public function test_a_tampered_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $query = $this->linkFor($user);

        // Someone else's id with my signature.
        $forgedId = $query;
        $forgedId['id'] = $other->ulid;
        $this->getJson($this->verifyUrl($forgedId))->assertForbidden();

        // Altered hash.
        $forgedHash = $query;
        $forgedHash['hash'] = sha1('attacker@example.com');
        $this->getJson($this->verifyUrl($forgedHash))->assertForbidden();

        // Extended expiry.
        $forgedExpiry = $query;
        $forgedExpiry['expires'] = (string) ((int) $query['expires'] + 86400);
        $this->getJson($this->verifyUrl($forgedExpiry))->assertForbidden();

        // No signature at all.
        $this->getJson('/api/v1/auth/verify-email/'.$query['id'].'/'.$query['hash'])->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $url = $this->verifyUrl($this->linkFor($user));

        $this->travel(2)->hours();

        $this->getJson($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_link_stops_working_after_the_email_changes(): void
    {
        $user = User::factory()->unverified()->create();
        $url = $this->verifyUrl($this->linkFor($user));

        $user->forceFill(['email' => 'new-address@example.com'])->save();

        $this->getJson($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_the_verification_email_can_be_resent_by_a_signed_in_user(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->spa()->postJson('/api/v1/auth/email/verification-notification')->assertAccepted();

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_nothing_is_resent_to_an_already_verified_user(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->spa()->postJson('/api/v1/auth/email/verification-notification')->assertAccepted();

        Notification::assertNothingSent();
    }

    public function test_resending_requires_authentication_and_is_rate_limited(): void
    {
        $this->spa()->postJson('/api/v1/auth/email/verification-notification')->assertUnauthorized();

        Notification::fake();
        $user = User::factory()->unverified()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)->spa()->postJson('/api/v1/auth/email/verification-notification')->assertAccepted();
        }

        $this->actingAs($user)->spa()->postJson('/api/v1/auth/email/verification-notification')->assertStatus(429);
    }
}
