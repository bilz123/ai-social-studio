<?php

namespace Tests\Feature\Authorization;

use App\Models\AuditLog;
use App\Models\GeneratedVideo;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\PublishedPost;
use App\Models\PublishingJob;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\VideoGenerationJob;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Tests\TestCase;

class OwnershipTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One full record graph for a user.
     *
     * @return array<string, Model>
     */
    private function recordsOf(User $user): array
    {
        $project = Project::factory()->for($user)->create();
        $video = GeneratedVideo::factory()
            ->for(VideoGenerationJob::factory()->for($project)->create(), 'generationJob')
            ->create();
        $job = PublishingJob::factory()->for($video)->create();

        return [
            'project' => $project,
            'image' => ProjectImage::factory()->for($project)->create(),
            'generation job' => $video->generationJob()->firstOrFail(),
            'video' => $video,
            'social account' => $job->socialAccount()->firstOrFail(),
            'publishing job' => $job,
            'published post' => PublishedPost::factory()->for($job, 'publishingJob')->create(),
            'scheduled post' => ScheduledPost::factory()->for($video)->create(),
        ];
    }

    public function test_users_can_act_on_their_own_records_only(): void
    {
        $alice = User::factory()->admin()->create();
        $bob = User::factory()->member()->create();
        $aliceRecords = $this->recordsOf($alice);
        $bobRecords = $this->recordsOf($bob);

        foreach ($aliceRecords as $label => $record) {
            foreach (['view', 'update', 'delete'] as $ability) {
                $this->assertTrue(Gate::forUser($alice)->allows($ability, $record), "alice {$ability} her {$label}");
                $this->assertTrue(Gate::forUser($bob)->denies($ability, $record), "bob {$ability} alice's {$label}");
            }
        }

        // An administrator has no implicit access to other people's content either.
        foreach ($bobRecords as $label => $record) {
            foreach (['view', 'update', 'delete'] as $ability) {
                $this->assertTrue(Gate::forUser($alice)->denies($ability, $record), "admin {$ability} bob's {$label}");
            }
        }
    }

    public function test_owned_by_scope_never_returns_other_users_rows(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $this->recordsOf($alice);
        $this->recordsOf($bob);

        $this->assertSame([$alice->id], Project::query()->ownedBy($alice)->pluck('user_id')->unique()->values()->all());
        $this->assertSame(1, VideoGenerationJob::query()->ownedBy($bob)->count());
        $this->assertSame(1, SocialAccount::query()->ownedBy($alice)->count());
        $this->assertSame(1, $alice->publishingJobs()->count());
        $this->assertSame(1, $bob->scheduledPosts()->count());
    }

    public function test_a_public_id_cannot_be_used_to_reach_someone_elses_record(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $bobProject = Project::factory()->for($bob)->create();

        // The pattern every future controller must use: look records up through the owner.
        $this->assertNull($alice->projects()->byPublicId($bobProject->ulid)->first());
        $this->assertNotNull($bob->projects()->byPublicId($bobProject->ulid)->first());
    }

    public function test_audit_logs_are_visible_to_their_owner_or_to_permitted_admins_only(): void
    {
        $alice = User::factory()->member()->create();
        $bob = User::factory()->member()->create();
        $admin = User::factory()->admin()->create();
        $log = $alice->auditLogs()->create(['action' => 'auth.login']);

        $this->assertTrue(Gate::forUser($alice)->allows('view', $log));
        $this->assertTrue(Gate::forUser($bob)->denies('view', $log));
        $this->assertTrue(Gate::forUser($admin)->allows('view', $log));
        $this->assertTrue(Gate::forUser($alice)->denies('update', $log));
        $this->assertTrue(Gate::forUser($admin)->denies('delete', $log));
    }

    public function test_audit_log_entries_are_immutable(): void
    {
        $user = User::factory()->create();
        $log = $user->auditLogs()->create(['action' => 'auth.login']);

        try {
            $log->update(['action' => 'tampered']);
            $this->fail('Updating an audit log entry must not be possible.');
        } catch (LogicException) {
            // expected
        }

        try {
            $log->delete();
            $this->fail('Deleting an audit log entry must not be possible.');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame('auth.login', AuditLog::query()->findOrFail($log->id)->action);
    }
}
