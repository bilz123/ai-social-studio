<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\Auth\PasswordChangedNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Brand-New-Passw0rd!';

    private function requestToken(User $user): string
    {
        Notification::fake();

        $this->spa()->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertAccepted();

        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        return (string) $token;
    }

    public function test_a_reset_link_is_sent_to_a_known_email(): void
    {
        $user = User::factory()->create();

        $token = $this->requestToken($user);

        $this->assertNotSame('', $token);
    }

    public function test_the_reset_email_links_to_the_spa(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $url = (new ResetPasswordNotification('abc123'))->toMail($user)->actionUrl;

        $this->assertStringStartsWith('http://localhost:8080/reset-password?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('abc123', $query['token']);
        $this->assertSame('ada@example.com', $query['email']);
    }

    public function test_the_response_does_not_reveal_whether_an_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $known = $this->spa()->postJson('/api/v1/auth/forgot-password', ['email' => 'ada@example.com']);
        $unknown = $this->spa()->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

        $known->assertAccepted();
        $unknown->assertAccepted();
        $this->assertSame($known->json(), $unknown->json());
        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
        Notification::assertCount(1);
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->spa()->postJson('/api/v1/auth/forgot-password', ['email' => 'ada@example.com'])->assertAccepted();
        }

        $this->spa()->postJson('/api/v1/auth/forgot-password', ['email' => 'ada@example.com'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited');
    }

    public function test_the_password_can_be_reset_with_a_valid_token(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $oldRememberToken = $user->remember_token;
        $token = $this->requestToken($user);

        $this->spa()->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'ada@example.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertNotSame($oldRememberToken, $user->remember_token);
        Notification::assertSentTo($user, PasswordChangedNotification::class);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'auth.password_reset']);

        // The new password works for signing in.
        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => self::NEW_PASSWORD])->assertOk();
    }

    public function test_a_token_cannot_be_used_twice(): void
    {
        $user = User::factory()->create();
        $token = $this->requestToken($user);
        $payload = [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];

        $this->spa()->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
        $this->spa()->postJson('/api/v1/auth/reset-password', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email'], 'errors');
    }

    public function test_an_invalid_token_or_wrong_email_is_rejected_with_one_message(): void
    {
        $user = User::factory()->create();
        $this->requestToken($user);

        $badToken = $this->spa()->postJson('/api/v1/auth/reset-password', [
            'token' => 'not-the-token',
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);
        $unknownEmail = $this->spa()->postJson('/api/v1/auth/reset-password', [
            'token' => 'not-the-token',
            'email' => 'nobody@example.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $badToken->assertStatus(422);
        $this->assertSame($badToken->json('errors'), $unknownEmail->json('errors'));
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_a_weak_new_password_is_rejected_and_the_token_stays_usable(): void
    {
        $user = User::factory()->create();
        $token = $this->requestToken($user);

        $this->spa()->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ])->assertStatus(422)->assertJsonValidationErrors(['password'], 'errors');

        $this->spa()->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $this->requestToken($user);

        $this->travel(61)->minutes();

        $this->spa()->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422);
        $this->assertFalse(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }
}
