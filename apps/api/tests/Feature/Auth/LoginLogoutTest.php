<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginLogoutTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng-Passw0rd!';

    public function test_csrf_cookie_endpoint_sets_the_xsrf_cookie(): void
    {
        $this->spa()->get('/api/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_a_user_can_log_in(): void
    {
        $user = User::factory()->member()->create(['email' => 'ada@example.com']);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'Ada@Example.com', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.id', $user->ulid)
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonMissingPath('data.password');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'auth.login']);
    }

    public function test_wrong_password_and_unknown_email_look_identical(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $wrongPassword = $this->spa()->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Wrong-Passw0rd!']);
        $unknownEmail = $this->spa()->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'Wrong-Passw0rd!']);

        $wrongPassword->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $unknownEmail->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->assertSame($wrongPassword->json('errors'), $unknownEmail->json('errors'));
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
    }

    public function test_sql_injection_attempts_do_not_authenticate(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => "ada@example.com' OR '1'='1", 'password' => "' OR '1'='1"])
            ->assertStatus(422);
        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => "' OR '1'='1' --"])
            ->assertStatus(422);

        $this->assertGuest();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertSame(1, User::query()->count());
    }

    public function test_login_is_rate_limited_per_account_and_ip(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->spa()->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => 'Wrong-Passw0rd!'])
                ->assertStatus(422);
        }

        // Even the correct password is refused once the limit is hit.
        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD])
            ->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited')
            ->assertHeader('Retry-After');
        $this->assertGuest();
    }

    public function test_login_is_refused_outside_the_web_app(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD])
            ->assertStatus(400);
        $this->assertGuest();
    }

    public function test_login_requires_a_csrf_token_in_a_real_environment(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        // The framework skips CSRF checks only while the environment is "testing".
        $this->app['env'] = 'local';

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD])
            ->assertStatus(419)
            ->assertJsonPath('code', 'csrf_token_mismatch');
        $this->assertGuest();
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');

        $user = User::factory()->member()->create();

        $this->actingAs($user)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->ulid)
            ->assertJsonPath('data.roles', ['member']);
    }

    public function test_a_user_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->spa()->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'auth.logout']);
    }

    public function test_logout_requires_authentication(): void
    {
        $this->spa()->postJson('/api/v1/auth/logout')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    }
}
