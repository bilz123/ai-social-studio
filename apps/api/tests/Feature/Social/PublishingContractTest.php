<?php

namespace Tests\Feature\Social;

use App\Contracts\Social\PublishesVideo;
use App\Contracts\Social\SocialIntegration;
use App\Enums\SocialPlatform;
use App\Exceptions\SocialIntegrationException;
use App\Models\SocialAccount;
use App\Services\Social\Data\VideoPublication;
use App\Services\Social\FacebookService;
use App\Services\Social\InstagramService;
use App\Services\Social\SocialIntegrationRegistry;
use App\Services\Social\SocialTokenManager;
use App\Services\Social\YouTubeService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/** The video publishing integration points (used by the publishing phase), against mocked providers. */
class PublishingContractTest extends SocialTestCase
{
    private function video(): VideoPublication
    {
        return new VideoPublication(
            videoUrl: 'https://storage.test/videos/1.mp4',
            title: 'Cold brew, 10 seconds',
            description: 'Slow pour.',
            tags: ['coldbrew', '#coffee'],
        );
    }

    public function test_every_platform_has_an_integration_that_can_publish_video(): void
    {
        $registry = app(SocialIntegrationRegistry::class);

        foreach (SocialPlatform::cases() as $platform) {
            $integration = $registry->for($platform);

            $this->assertInstanceOf(SocialIntegration::class, $integration);
            $this->assertInstanceOf(PublishesVideo::class, $integration);
            $this->assertSame($platform, $integration->platform());
        }

        $this->assertInstanceOf(FacebookService::class, $registry->for(SocialPlatform::FacebookPage));
        $this->assertInstanceOf(InstagramService::class, $registry->for(SocialPlatform::InstagramBusiness));
        $this->assertInstanceOf(YouTubeService::class, $registry->for(SocialPlatform::YoutubeChannel));
    }

    public function test_the_caption_appends_normalised_hashtags(): void
    {
        $this->assertSame("Slow pour.\n\n#coldbrew #coffee", $this->video()->caption());
        $this->assertSame('Only text', (new VideoPublication('https://x.test/v.mp4', 't', 'Only text'))->caption());
    }

    public function test_facebook_posts_the_video_to_the_page(): void
    {
        $account = SocialAccount::factory()->for($this->member())->facebook()->withToken(['access_token' => 'page-token-111'])->create(['external_id' => '111']);
        Http::fake(['graph.facebook.com/*/111/videos*' => Http::response(['id' => '9001'])]);

        $published = app(FacebookService::class)->publishVideo($account, $account->tokens()->firstOrFail(), $this->video());

        $this->assertSame('9001', $published->externalId);
        $this->assertSame('https://www.facebook.com/watch/?v=9001', $published->url);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer page-token-111')
            && $request['file_url'] === 'https://storage.test/videos/1.mp4'
            && str_contains($request['description'], '#coldbrew'));
    }

    public function test_instagram_creates_a_reel_waits_for_processing_then_publishes(): void
    {
        $account = SocialAccount::factory()->for($this->member())->instagram()->withToken(['access_token' => 'ig-token'])->create(['external_id' => '1784']);
        Http::fake([
            'graph.instagram.com/*/1784/media_publish*' => Http::response(['id' => 'media-1']),
            'graph.instagram.com/*/1784/media*' => Http::response(['id' => 'container-1']),
            'graph.instagram.com/*/container-1?*' => Http::sequence()
                ->push(['status_code' => 'IN_PROGRESS'])
                ->push(['status_code' => 'FINISHED']),
            'graph.instagram.com/*/media-1?*' => Http::response(['permalink' => 'https://www.instagram.com/reel/abc/']),
        ]);

        $published = app(InstagramService::class)->publishVideo($account, $account->tokens()->firstOrFail(), $this->video());

        $this->assertSame('media-1', $published->externalId);
        $this->assertSame('https://www.instagram.com/reel/abc/', $published->url);
        Sleep::assertSleptTimes(1);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/1784/media')
            && $request['media_type'] === 'REELS'
            && $request['video_url'] === 'https://storage.test/videos/1.mp4'
            && $request->hasHeader('Authorization', 'Bearer ig-token'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/1784/media_publish') && $request['creation_id'] === 'container-1');
    }

    public function test_instagram_reports_a_video_it_cannot_process(): void
    {
        $account = SocialAccount::factory()->for($this->member())->instagram()->withToken()->create(['external_id' => '1784']);
        Http::fake([
            'graph.instagram.com/*/1784/media*' => Http::response(['id' => 'container-1']),
            'graph.instagram.com/*/container-1?*' => Http::response(['status_code' => 'ERROR']),
        ]);

        try {
            app(InstagramService::class)->publishVideo($account, $account->tokens()->firstOrFail(), $this->video());
            $this->fail('Expected a SocialIntegrationException.');
        } catch (SocialIntegrationException $e) {
            $this->assertSame('provider_error', $e->errorCode);
            $this->assertStringContainsString('could not process', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'media_publish'));
    }

    public function test_youtube_uploads_with_a_resumable_session(): void
    {
        $account = SocialAccount::factory()->for($this->member())->youtube()->withToken(['access_token' => 'ya29.up'])->create(['external_id' => 'UC1']);
        $session = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=abc123';
        Http::fake([
            'storage.test/videos/1.mp4' => Http::response('VIDEO-BYTES', 200, ['Content-Type' => 'video/mp4']),
            'www.googleapis.com/upload/youtube/v3/videos*' => fn (Request $request) => $request->method() === 'PUT'
                ? Http::response(['id' => 'yt-77'])
                : Http::response([], 200, ['Location' => $session]),
        ]);

        $published = app(YouTubeService::class)->publishVideo($account, $account->tokens()->firstOrFail(), $this->video());

        $this->assertSame('yt-77', $published->externalId);
        $this->assertSame('https://www.youtube.com/watch?v=yt-77', $published->url);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), 'uploadType=resumable')
            && $request->hasHeader('Authorization', 'Bearer ya29.up')
            && $request->hasHeader('X-Upload-Content-Length', '11')
            && $request['snippet']['title'] === 'Cold brew, 10 seconds'
            && $request['status']['privacyStatus'] === 'private');
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === $session
            && $request->body() === 'VIDEO-BYTES');
    }

    public function test_youtube_upload_quota_errors_are_reported_as_rate_limits(): void
    {
        $account = SocialAccount::factory()->for($this->member())->youtube()->withToken()->create(['external_id' => 'UC1']);
        Http::fake([
            'storage.test/videos/1.mp4' => Http::response('VIDEO-BYTES'),
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response(['error' => ['code' => 403, 'errors' => [['reason' => 'uploadLimitExceeded']]]], 403),
        ]);

        try {
            app(YouTubeService::class)->publishVideo($account, $account->tokens()->firstOrFail(), $this->video());
            $this->fail('Expected a SocialIntegrationException.');
        } catch (SocialIntegrationException $e) {
            $this->assertSame('provider_rate_limited', $e->errorCode);
        }
    }

    public function test_a_fresh_token_is_renewed_before_publishing(): void
    {
        $account = SocialAccount::factory()->for($this->member())->youtube()
            ->withToken(['access_token' => 'stale', 'refresh_token' => 'rt-1', 'expires_at' => now()->addMinute()])->create(['external_id' => 'UC1']);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'renewed', 'expires_in' => 3599, 'token_type' => 'Bearer'])]);

        $token = app(SocialTokenManager::class)->freshToken($account);

        $this->assertSame('renewed', $token->access_token);
        $this->assertSame('rt-1', $token->refresh_token);
    }

    public function test_publishing_with_a_deleted_connection_asks_for_reconnect(): void
    {
        $account = SocialAccount::factory()->for($this->member())->youtube()->create();

        try {
            app(SocialTokenManager::class)->freshToken($account);
            $this->fail('Expected a SocialIntegrationException.');
        } catch (SocialIntegrationException $e) {
            $this->assertTrue($e->requiresReconnect());
        }
    }
}
