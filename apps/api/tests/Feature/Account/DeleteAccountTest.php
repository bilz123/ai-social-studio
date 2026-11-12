<?php

namespace Tests\Feature\Account;

use App\Models\GeneratedVideo;
use App\Models\Project;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Models\User;
use App\Models\VideoGenerationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng-Passw0rd!';

    public function test_it_requires_authentication(): void
    {
        $this->deleteJson('/api/v1/account', ['password' => self::PASSWORD])->assertUnauthorized();
    }

    public function test_the_password_is_required_and_must_be_correct(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->deleteJson('/api/v1/account', [])->assertStatus(422)->assertJsonValidationErrors(['password'], 'errors');
        $this->actingAs($user)->deleteJson('/api/v1/account', ['password' => 'Wrong-Passw0rd!'])
            ->assertStatus(422)->assertJsonValidationErrors(['password'], 'errors');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_deleting_the_account_removes_the_user_and_everything_they_own(): void
    {
        $user = User::factory()->member()->create();
        $other = User::factory()->member()->create();

        $project = Project::factory()->for($user)->create();
        $job = VideoGenerationJob::factory()->for($project)->create();
        GeneratedVideo::factory()->for($job, 'generationJob')->create();
        $account = SocialAccount::factory()->for($user)->create();
        SocialToken::factory()->for($account)->create();
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'x', 'created_at' => now()]);
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'data' => ['x' => 1],
        ]);

        // Someone else's data must survive.
        $otherProject = Project::factory()->for($other)->create();

        $this->actingAs($user)->deleteJson('/api/v1/account', ['password' => self::PASSWORD])->assertNoContent();

        $this->assertGuest('web');
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseHas('projects', ['id' => $otherProject->id]);
        $this->assertDatabaseCount('video_generation_jobs', 0);
        $this->assertDatabaseCount('generated_videos', 0);
        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertDatabaseCount('social_tokens', 0);
        $this->assertDatabaseCount('role_user', 1);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('users', ['id' => $other->id]);
    }

    public function test_an_anonymous_audit_entry_survives_deletion(): void
    {
        $user = User::factory()->create();
        $user->auditLogs()->create(['action' => 'auth.login']);

        $this->actingAs($user)->deleteJson('/api/v1/account', ['password' => self::PASSWORD])->assertNoContent();

        // Earlier entries lose their actor but remain; the deletion itself is recorded.
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.deleted', 'user_id' => null]);
        $this->assertStringNotContainsString($user->email, (string) json_encode(DB::table('audit_logs')->get()));
    }
}
