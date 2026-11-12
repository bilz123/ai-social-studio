<?php

namespace Tests\Feature\Account;

use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng-Passw0rd!';

    public function test_the_profile_requires_authentication(): void
    {
        $this->getJson('/api/v1/account/profile')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->patchJson('/api/v1/account/profile', ['name' => 'Hacker'])->assertUnauthorized();
    }

    public function test_a_user_sees_only_their_own_profile(): void
    {
        $user = User::factory()->member()->create(['name' => 'Ada']);
        User::factory()->create(['name' => 'Grace']);

        $this->actingAs($user)->getJson('/api/v1/account/profile')
            ->assertOk()
            ->assertJsonPath('data.name', 'Ada')
            ->assertJsonPath('data.id', $user->ulid);
    }

    public function test_the_name_can_be_changed_without_a_password(): void
    {
        $user = User::factory()->create(['name' => 'Ada']);

        $this->actingAs($user)->patchJson('/api/v1/account/profile', ['name' => 'Ada Lovelace'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ada Lovelace');

        $this->assertSame('Ada Lovelace', $user->fresh()->name);
    }

    public function test_changing_the_email_requires_the_current_password(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->actingAs($user)->patchJson('/api/v1/account/profile', ['email' => 'new@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password'], 'errors');

        $this->actingAs($user)->patchJson('/api/v1/account/profile', ['email' => 'new@example.com', 'current_password' => 'Wrong-Passw0rd!'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password'], 'errors');

        $this->assertSame('ada@example.com', $user->fresh()->email);
    }

    public function test_a_new_email_must_be_verified_again(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->actingAs($user)->patchJson('/api/v1/account/profile', [
            'email' => 'New@Example.com',
            'current_password' => self::PASSWORD,
        ])->assertOk()
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('data.email_verified', false);

        $this->assertNull($user->fresh()->email_verified_at);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'account.email_changed']);
    }

    public function test_an_email_taken_by_someone_else_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->actingAs($user)->patchJson('/api/v1/account/profile', [
            'email' => 'taken@example.com',
            'current_password' => self::PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors(['email'], 'errors');
    }

    public function test_keeping_the_same_email_is_allowed(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->actingAs($user)->patchJson('/api/v1/account/profile', ['name' => 'Ada L', 'email' => 'ada@example.com'])
            ->assertOk()
            ->assertJsonPath('data.email_verified', true);
    }

    public function test_privileged_fields_cannot_be_changed_through_the_profile(): void
    {
        $user = User::factory()->unverified()->member()->create();
        $originalUlid = $user->ulid;

        $this->actingAs($user)->patchJson('/api/v1/account/profile', [
            'name' => 'Ada',
            'email_verified_at' => now()->toDateTimeString(),
            'ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'id' => 999,
            'roles' => ['admin'],
            'password' => 'Hacked-Passw0rd!',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertNull($fresh->email_verified_at);
        $this->assertSame($originalUlid, $fresh->ulid);
        $this->assertSame($user->id, $fresh->id);
        $this->assertFalse($fresh->hasRole('admin'));
        $this->assertTrue(password_verify(self::PASSWORD, $fresh->password));
    }

    public function test_markup_in_the_name_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/v1/account/profile', ['name' => '<img src=x onerror=alert(1)>'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name'], 'errors');
    }

    public function test_a_user_sees_only_their_own_activity(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $user->auditLogs()->create(['action' => 'auth.login', 'ip' => '127.0.0.1']);
        $other->auditLogs()->create(['action' => 'auth.login_failed', 'ip' => '10.0.0.9']);

        $response = $this->actingAs($user)->getJson('/api/v1/account/activity')->assertOk();

        $this->assertSame(['auth.login'], collect($response->json('data'))->pluck('action')->all());
        $this->assertStringNotContainsString('10.0.0.9', (string) $response->getContent());
    }
}
