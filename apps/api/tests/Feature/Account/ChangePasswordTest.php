<?php

namespace Tests\Feature\Account;

use App\Models\User;
use App\Notifications\Auth\PasswordChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT = 'Str0ng-Passw0rd!';

    private const NEW = 'Another-Str0ng-One!';

    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'current_password' => self::CURRENT,
            'password' => self::NEW,
            'password_confirmation' => self::NEW,
        ], $overrides);
    }

    public function test_it_requires_authentication(): void
    {
        $this->putJson('/api/v1/account/password', $this->payload())->assertUnauthorized();
    }

    public function test_a_user_can_change_their_password(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/v1/account/password', $this->payload())->assertNoContent();

        $this->assertTrue(Hash::check(self::NEW, $user->fresh()->password));
        $this->assertFalse(Hash::check(self::CURRENT, $user->fresh()->password));
        Notification::assertSentTo($user, PasswordChangedNotification::class);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'account.password_changed']);
    }

    public function test_the_current_password_must_be_correct(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/v1/account/password', $this->payload(['current_password' => 'Wrong-Passw0rd!']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password'], 'errors');

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }

    public function test_the_new_password_must_be_strong_confirmed_and_different(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/v1/account/password', $this->payload(['password' => 'weak', 'password_confirmation' => 'weak']))
            ->assertStatus(422)->assertJsonValidationErrors(['password'], 'errors');

        $this->actingAs($user)->putJson('/api/v1/account/password', $this->payload(['password_confirmation' => 'Mismatch-Passw0rd!']))
            ->assertStatus(422)->assertJsonValidationErrors(['password'], 'errors');

        $this->actingAs($user)->putJson('/api/v1/account/password', $this->payload(['password' => self::CURRENT, 'password_confirmation' => self::CURRENT]))
            ->assertStatus(422)->assertJsonValidationErrors(['password'], 'errors');

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }

    public function test_changing_a_password_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->putJson('/api/v1/account/password', $this->payload(['current_password' => 'Wrong-Passw0rd!']))
                ->assertStatus(422);
        }

        $this->actingAs($user)->putJson('/api/v1/account/password', $this->payload())
            ->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited');
        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }
}
