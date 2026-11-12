<?php

namespace Tests\Feature\Database;

use App\Enums\ApprovalStatus;
use App\Enums\GenerationStatus;
use App\Models\GeneratedVideo;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\PublishedPost;
use App\Models\PublishingJob;
use App\Models\ScheduledPost;
use App\Models\SocialToken;
use App\Models\User;
use App\Models\VideoGenerationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_whole_object_graph_hangs_together(): void
    {
        $user = User::factory()->member()->create();
        $project = Project::factory()->for($user)->create();
        ProjectImage::factory()->for($project)->count(3)->sequence(
            ['position' => 1], ['position' => 2], ['position' => 3],
        )->create();
        $job = VideoGenerationJob::factory()->for($project)->create(['version' => 1]);
        $video = GeneratedVideo::factory()->for($job, 'generationJob')->create();
        $publishing = PublishingJob::factory()->for($video)->create();
        $post = PublishedPost::factory()->for($publishing, 'publishingJob')->create();
        $scheduled = ScheduledPost::factory()->for($video)->create();
        $account = $publishing->socialAccount()->firstOrFail();
        $token = SocialToken::factory()->for($account)->create();

        $loadedProject = Project::query()->with(['images', 'videoGenerationJobs.generatedVideos'])->findOrFail($project->id);
        $this->assertCount(3, $loadedProject->images);
        $this->assertSame([1, 2, 3], $loadedProject->images->pluck('position')->all(), 'images come back ordered');
        $this->assertCount(1, $loadedProject->videoGenerationJobs);
        $this->assertCount(1, $loadedProject->videoGenerationJobs->first()->generatedVideos);

        $loadedVideo = GeneratedVideo::query()->with(['generationJob.project.user', 'publishingJobs.publishedPost', 'publishingJobs.socialAccount'])->findOrFail($video->id);
        $this->assertTrue($loadedVideo->generationJob->project->user->is($user));
        $this->assertTrue($loadedVideo->publishingJobs->first()->publishedPost->is($post));
        $this->assertTrue($loadedVideo->publishingJobs->first()->socialAccount->is($account));

        $this->assertTrue($post->publishingJob()->firstOrFail()->is($publishing));
        $this->assertTrue($post->socialAccount()->firstOrFail()->is($account));
        $this->assertTrue($scheduled->generatedVideo()->firstOrFail()->is($video));
        $this->assertTrue($scheduled->socialAccount()->firstOrFail()->user()->firstOrFail()->is($user));
        $this->assertTrue($token->socialAccount()->firstOrFail()->is($account));
        $this->assertTrue($account->tokens()->firstOrFail()->is($token));

        $this->assertSame(1, $user->projects()->count());
        $this->assertSame(1, $user->videoGenerationJobs()->count());
        $this->assertSame(1, $user->generatedVideos()->count());
        $this->assertSame(1, $user->publishingJobs()->count());
        $this->assertSame(1, $user->publishedPosts()->count());
        $this->assertSame(1, $user->scheduledPosts()->count());
        // One destination for the publishing job and one for the scheduled post.
        $this->assertSame(2, $user->socialAccounts()->count());
    }

    public function test_enum_and_json_casts_round_trip(): void
    {
        $job = VideoGenerationJob::factory()->create(['params' => ['notes' => 'warm light'], 'status' => GenerationStatus::Generating]);
        $video = GeneratedVideo::factory()->for($job, 'generationJob')->approved()->create();

        $this->assertSame(GenerationStatus::Generating, $job->fresh()->status);
        $this->assertSame(['notes' => 'warm light'], $job->fresh()->params);
        $this->assertSame(ApprovalStatus::Approved, $video->fresh()->approval_status);
        $this->assertTrue($video->fresh()->isApproved());
        $this->assertFalse(GeneratedVideo::factory()->for($job->fresh(), 'generationJob')->create(['object_key' => 'other'])->isApproved());
    }

    public function test_roles_and_permissions_relate_to_users(): void
    {
        $admin = User::factory()->admin()->create();

        $role = $admin->roles()->with('permissions')->firstOrFail();
        $this->assertSame('admin', $role->name);
        $this->assertGreaterThan(4, $role->permissions->count());
        $this->assertSame(1, $role->users()->count());
    }

    public function test_public_ids_are_ulids_and_drive_route_binding(): void
    {
        $project = Project::factory()->create();

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $project->ulid);
        $this->assertSame('ulid', $project->getRouteKeyName());
        $this->assertSame($project->ulid, $project->getRouteKey());
        $this->assertTrue(Project::query()->byPublicId($project->ulid)->firstOrFail()->is($project));
    }

    public function test_soft_deleted_accounts_keep_their_publishing_history(): void
    {
        $publishing = PublishingJob::factory()->create();
        $account = $publishing->socialAccount()->firstOrFail();

        $account->delete();

        $this->assertSoftDeleted('social_accounts', ['id' => $account->id]);
        $this->assertTrue(PublishingJob::query()->with('socialAccount')->findOrFail($publishing->id)->socialAccount->is($account));
    }
}
