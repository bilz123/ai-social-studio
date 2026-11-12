<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng-Passw0rd!';

    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ], $overrides);
    }

    public function test_a_visitor_can_register(): void
    {
        Notification::fake();

        $response = $this->spa()->postJson('/api/v1/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Ada Lovelace')
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.roles', ['member'])
            ->assertJsonStructure(['data' => ['id', 'permissions', 'created_at']]);

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $this->assertSame(26, strlen($user->ulid));
        $this->assertSame($user->ulid, $response->json('data.id'));
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasRole('member'));
        $this->assertTrue($user->hasPermission('projects.manage'));
        $this->assertFalse($user->hasPermission('users.view'));
        Notification::assertSentTo($user, VerifyEmailNotification::class);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'auth.registered']);
    }

    public function test_the_password_is_hashed_and_never_returned(): void
    {
        $response = $this->spa()->postJson('/api/v1/auth/register', $this->payload());

        $stored = (string) User::query()->value('password');
        $this->assertNotSame(self::PASSWORD, $stored);
        $this->assertStringStartsWith('$2y$', $stored);
        $this->assertTrue(Hash::check(self::PASSWORD, $stored));

        $this->assertStringNotContainsString(self::PASSWORD, (string) $response->getContent());
        $this->assertStringNotContainsString('password', strtolower((string) json_encode(array_keys($response->json('data')))));
        $this->assertStringNotContainsString('remember_token', (string) $response->getContent());
    }

    public function test_registration_ignores_attempts_to_set_privileged_fields(): void
    {
        $this->spa()->postJson('/api/v1/auth/register', $this->payload([
            'email_verified_at' => '2020-01-01 00:00:00',
            'id' => 1,
            'ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'roles' => ['admin'],
            'role' => 'admin',
            'permissions' => ['users.manage'],
        ]))->assertCreated();

        $user = User::query()->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertNotSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', $user->ulid);
        $this->assertFalse($user->hasRole('admin'));
        $this->assertFalse($user->hasPermission('users.manage'));
    }

    public function test_validation_rejects_bad_input(): void
    {
        // Many attempts in one test: rate limiting has its own test.
        $this->withoutMiddleware(ThrottleRequests::class);

        $cases = [
            'missing everything' => [[], ['name', 'email', 'password']],
            'weak password' => [['password' => 'password', 'password_confirmation' => 'password'], ['password']],
            'short password' => [['password' => 'Ab1!', 'password_confirmation' => 'Ab1!'], ['password']],
            'no symbol' => [['password' => 'Abcdefghijk1', 'password_confirmation' => 'Abcdefghijk1'], ['password']],
            'confirmation mismatch' => [['password_confirmation' => 'Different-Passw0rd!'], ['password']],
            'invalid email' => [['email' => 'not-an-email'], ['email']],
            'markup in name' => [['name' => '<script>alert(1)</script>'], ['name']],
            'password over 72 bytes' => [['password' => str_repeat('Aa1!', 19), 'password_confirmation' => str_repeat('Aa1!', 19)], ['password']],
        ];

        foreach ($cases as $label => [$overrides, $invalidFields]) {
            $payload = $overrides === [] ? [] : $this->payload($overrides);

            $this->spa()->postJson('/api/v1/auth/register', $payload)
                ->assertStatus(422)
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonValidationErrors($invalidFields, 'errors');

            $this->assertSame(0, User::query()->count(), "No user must be created for case: {$label}");
        }
    }

    public function test_duplicate_emails_are_rejected_case_insensitively(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->spa()->postJson('/api/v1/auth/register', $this->payload(['email' => 'ADA@Example.com']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email'], 'errors');

        $this->assertSame(1, User::query()->count());
    }

    public function test_email_is_stored_lower_cased_and_trimmed(): void
    {
        $this->spa()->postJson('/api/v1/auth/register', $this->payload(['email' => '  Ada@Example.COM ']))->assertCreated();

        $this->assertSame('ada@example.com', User::query()->value('email'));
    }

    public function test_registration_is_refused_outside_the_web_app(): void
    {
        // No SPA Origin: Sanctum treats this as a stateless client, which cannot hold a session.
        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(400)
            ->assertJsonPath('code', 'bad_request');

        $this->assertSame(0, User::query()->count());
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->spa()->postJson('/api/v1/auth/register', []);
        }

        $this->spa()->postJson('/api/v1/auth/register', [])
            ->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited')
            ->assertHeader('Retry-After');
    }

    public function test_registration_leaves_an_audit_trail_without_secrets(): void
    {
        $this->spa()->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $log = AuditLog::query()->where('action', 'auth.registered')->firstOrFail();
        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode($log->toArray()));
        $this->assertNotNull($log->ip);
    }
}
