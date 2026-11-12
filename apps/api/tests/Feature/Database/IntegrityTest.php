<?php

namespace Tests\Feature\Database;

use App\Models\GeneratedVideo;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\PublishedPost;
use App\Models\PublishingJob;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Models\User;
use App\Models\VideoGenerationJob;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrityTest extends TestCase
{
    use RefreshDatabase;

    // --- Foreign keys: a row can never point at another user's data -------------------------

    public function test_a_project_image_cannot_be_attached_to_another_users_project(): void
    {
        $alice = User::factory()->create();
        $bobProject = Project::factory()->create();

        $this->expectException(QueryException::class);

        ProjectImage::factory()->create(['project_id' => $bobProject->id, 'user_id' => $alice->id]);
    }

    public function test_a_generation_job_cannot_use_another_users_project(): void
    {
        $alice = User::factory()->create();
        $bobProject = Project::factory()->create();

        $this->expectException(QueryException::class);

        VideoGenerationJob::factory()->create(['project_id' => $bobProject->id, 'user_id' => $alice->id]);
    }

    public function test_a_video_cannot_belong_to_another_users_generation_job(): void
    {
        $alice = User::factory()->create();
        $bobJob = VideoGenerationJob::factory()->create();

        $this->expectException(QueryException::class);

        GeneratedVideo::factory()->create(['generation_job_id' => $bobJob->id, 'user_id' => $alice->id]);
    }

    public function test_publishing_cannot_use_another_users_video_or_social_account(): void
    {
        $alice = User::factory()->create();
        $aliceAccount = SocialAccount::factory()->for($alice)->create();
        $bobVideo = GeneratedVideo::factory()->create();

        try {
            PublishingJob::factory()->create([
                'generated_video_id' => $bobVideo->id,
                'user_id' => $alice->id,
                'social_account_id' => $aliceAccount->id,
            ]);
            $this->fail("Publishing someone else's video must violate a foreign key.");
        } catch (QueryException) {
            // expected
        }

        $aliceVideo = GeneratedVideo::factory()
            ->for(VideoGenerationJob::factory()->for(Project::factory()->for($alice)->create())->create(), 'generationJob')
            ->create();
        $bobAccount = SocialAccount::factory()->create();

        $this->expectException(QueryException::class);

        PublishingJob::factory()->create([
            'generated_video_id' => $aliceVideo->id,
            'user_id' => $alice->id,
            'social_account_id' => $bobAccount->id,
        ]);
    }

    public function test_scheduling_cannot_use_another_users_social_account(): void
    {
        $alice = User::factory()->create();
        $aliceVideo = GeneratedVideo::factory()
            ->for(VideoGenerationJob::factory()->for(Project::factory()->for($alice)->create())->create(), 'generationJob')
            ->create();
        $bobAccount = SocialAccount::factory()->create();

        $this->expectException(QueryException::class);

        ScheduledPost::factory()->create([
            'generated_video_id' => $aliceVideo->id,
            'user_id' => $alice->id,
            'social_account_id' => $bobAccount->id,
        ]);
    }

    public function test_a_published_post_must_match_its_publishing_job_owner(): void
    {
        $alice = User::factory()->create();
        $bobJob = PublishingJob::factory()->create();

        $this->expectException(QueryException::class);

        PublishedPost::factory()->create([
            'publishing_job_id' => $bobJob->id,
            'user_id' => $alice->id,
            'social_account_id' => $bobJob->social_account_id,
        ]);
    }

    // --- Cascades ---------------------------------------------------------------------------

    public function test_deleting_a_user_removes_everything_they_own(): void
    {
        $user = User::factory()->member()->create();
        $project = Project::factory()->for($user)->create();
        ProjectImage::factory()->for($project)->create();
        $video = GeneratedVideo::factory()->for(VideoGenerationJob::factory()->for($project)->create(), 'generationJob')->create();
        $job = PublishingJob::factory()->for($video)->create();
        PublishedPost::factory()->for($job, 'publishingJob')->create();
        ScheduledPost::factory()->for($video)->create();
        SocialToken::factory()->for($job->socialAccount()->firstOrFail())->create();
        $user->auditLogs()->create(['action' => 'x']);

        $user->delete();

        foreach (['projects', 'project_images', 'video_generation_jobs', 'generated_videos', 'publishing_jobs', 'published_posts', 'scheduled_posts', 'social_accounts', 'social_tokens', 'role_user'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        // History is kept but anonymised.
        $this->assertDatabaseHas('audit_logs', ['action' => 'x', 'user_id' => null]);
    }

    public function test_deleting_a_project_cascades_down_the_pipeline(): void
    {
        $project = Project::factory()->create();
        ProjectImage::factory()->for($project)->create();
        $video = GeneratedVideo::factory()->for(VideoGenerationJob::factory()->for($project)->create(), 'generationJob')->create();
        PublishingJob::factory()->for($video)->create();

        $project->forceDelete();

        foreach (['project_images', 'video_generation_jobs', 'generated_videos', 'publishing_jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('social_accounts', 1);
    }

    // --- Uniqueness -------------------------------------------------------------------------

    public function test_unique_constraints_hold(): void
    {
        $project = Project::factory()->create();
        ProjectImage::factory()->for($project)->create(['position' => 1]);

        try {
            ProjectImage::factory()->for($project)->create(['position' => 1]);
            $this->fail('Two images cannot share a position within a project.');
        } catch (QueryException) {
            // expected
        }

        VideoGenerationJob::factory()->for($project)->create(['version' => 1]);
        try {
            VideoGenerationJob::factory()->for($project)->create(['version' => 1]);
            $this->fail('A project cannot have two generations with the same version.');
        } catch (QueryException) {
            // expected
        }

        $user = User::factory()->create();
        SocialAccount::factory()->for($user)->create(['external_id' => '123']);
        try {
            SocialAccount::factory()->for($user)->create(['external_id' => '123']);
            $this->fail('The same platform account cannot be connected twice by one user.');
        } catch (QueryException) {
            // expected
        }
        // Another user may connect the same page.
        SocialAccount::factory()->create(['external_id' => '123']);

        $this->expectException(QueryException::class);
        User::factory()->create(['email' => User::query()->value('email')]);
    }

    // --- Token encryption -------------------------------------------------------------------

    public function test_oauth_tokens_are_encrypted_at_rest(): void
    {
        $token = SocialToken::factory()->create([
            'access_token' => 'plain-access-token-123',
            'refresh_token' => 'plain-refresh-token-456',
        ]);

        $raw = DB::table('social_tokens')->where('id', $token->id)->first();
        $this->assertStringNotContainsString('plain-access-token-123', $raw->access_token);
        $this->assertStringNotContainsString('plain-refresh-token-456', $raw->refresh_token);
        $this->assertSame('plain-access-token-123', Crypt::decryptString($raw->access_token));

        $fresh = SocialToken::query()->findOrFail($token->id);
        $this->assertSame('plain-access-token-123', $fresh->access_token);
        $this->assertSame('plain-refresh-token-456', $fresh->refresh_token);
    }

    public function test_oauth_tokens_never_appear_in_serialised_output(): void
    {
        $token = SocialToken::factory()->create(['access_token' => 'plain-access-token-123', 'refresh_token' => 'plain-refresh-token-456']);

        $json = $token->fresh()->toJson();

        $this->assertStringNotContainsString('plain-access-token-123', $json);
        $this->assertStringNotContainsString('plain-refresh-token-456', $json);
        $this->assertStringNotContainsString('access_token', $json);
        $this->assertStringNotContainsString('plain-access-token-123', (string) json_encode(SocialAccount::query()->with('tokens')->get()));
    }

    public function test_a_null_refresh_token_is_stored_as_null(): void
    {
        $token = SocialToken::factory()->create(['refresh_token' => null]);

        $this->assertNull(DB::table('social_tokens')->where('id', $token->id)->value('refresh_token'));
        $this->assertNull($token->fresh()->refresh_token);
    }

    // --- Mass assignment --------------------------------------------------------------------

    public function test_mass_assignment_of_protected_attributes_fails_loudly(): void
    {
        $this->expectException(MassAssignmentException::class);

        new User(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'x', 'email_verified_at' => now()]);
    }

    public function test_ownership_and_state_columns_are_not_mass_assignable(): void
    {
        foreach ([
            fn () => new Project(['title' => 't', 'user_id' => 1]),
            fn () => new Project(['title' => 't', 'status' => 'ready']),
            fn () => new VideoGenerationJob(['style_preset' => 'x', 'status' => 'ready_for_review']),
            fn () => new VideoGenerationJob(['style_preset' => 'x', 'cost_cents' => 0]),
            fn () => new SocialAccount(['status' => 'active']),
            fn () => new ProjectImage(['project_id' => 1]),
            fn () => new GeneratedVideo(['approval_status' => 'approved']),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A protected attribute was mass assignable.');
            } catch (MassAssignmentException) {
                // expected
            }
        }

        $this->assertTrue(true);
    }

    public function test_sql_special_characters_are_stored_and_queried_as_data(): void
    {
        $user = User::factory()->create();
        $evil = "x'); DROP TABLE users; --";

        $project = $user->projects()->create(['title' => $evil, 'product_name' => $evil, 'product_description' => $evil]);

        $this->assertSame($evil, $project->fresh()->title);
        $this->assertSame(0, Project::query()->where('title', "' OR '1'='1")->count());
        $this->assertSame(1, Project::query()->where('title', $evil)->count());
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}
