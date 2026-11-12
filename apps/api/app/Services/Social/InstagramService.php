<?php

namespace App\Services\Social;

use App\Contracts\Social\PublishesVideo;
use App\Exceptions\SocialIntegrationException;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Services\Social\Concerns\ClassifiesMetaErrors;
use App\Services\Social\Data\AccountSnapshot;
use App\Services\Social\Data\DiscoveredAccount;
use App\Services\Social\Data\PublishedVideo;
use App\Services\Social\Data\TokenSet;
use App\Services\Social\Data\VideoPublication;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Sleep;

/**
 * Instagram API with Instagram Login (a.k.a. Business Login for Instagram).
 *
 * Works for Instagram professional accounts (Business and Creator) and needs no Facebook Page.
 * Flow: consent at instagram.com → code → short-lived token (api.instagram.com) → 60-day
 * long-lived token (graph.instagram.com, `ig_exchange_token`). Long-lived tokens are renewed with
 * `ig_refresh_token`, which only works on unexpired tokens at least 24 hours old.
 */
class InstagramService extends AbstractSocialService implements PublishesVideo
{
    use ClassifiesMetaErrors;

    /** Instagram account types that can use the API. */
    private const ELIGIBLE_TYPES = ['BUSINESS', 'MEDIA_CREATOR'];

    private const PROFILE_FIELDS = 'user_id,username,name,account_type,profile_picture_url,followers_count,follows_count,media_count';

    protected function configKey(): string
    {
        return 'instagram';
    }

    public function supportsRefresh(): bool
    {
        return true;
    }

    public function authorizationUrl(string $state, ?string $codeChallenge = null): string
    {
        $this->assertConfigured();

        return 'https://www.instagram.com/oauth/authorize?'.http_build_query([
            'client_id' => $this->config('client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(',', $this->requiredScopes()),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function completeAuthorization(string $code, ?string $codeVerifier = null): array
    {
        $this->assertConfigured();

        // Instagram appends "#_" to the code in the redirect; it is not part of the code.
        $code = preg_replace('/#_$/', '', $code) ?? $code;

        try {
            $short = $this->send(fn ($http) => $http->asForm()->post('https://api.instagram.com/oauth/access_token', [
                'client_id' => $this->config('client_id'),
                'client_secret' => $this->config('client_secret'),
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUri(),
                'code' => $code,
            ]))->json();

            // The response is either flat or wrapped as {"data": [{...}]}, depending on app version.
            $short = is_array($short['data'][0] ?? null) ? $short['data'][0] : (array) $short;

            if (! is_string($short['access_token'] ?? null)) {
                throw SocialIntegrationException::authorizationFailed($this->label());
            }

            $long = $this->send(fn ($http) => $http->get('https://graph.instagram.com/access_token', [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => $this->config('client_secret'),
                'access_token' => $short['access_token'],
            ]));
        } catch (SocialIntegrationException $e) {
            throw $e->errorCode === 'provider_error' ? SocialIntegrationException::authorizationFailed($this->label()) : $e;
        }

        $accessToken = $long->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw SocialIntegrationException::authorizationFailed($this->label());
        }

        $granted = $this->parsePermissions($short['permissions'] ?? null) ?? $this->requiredScopes();
        $missing = array_values(array_diff($this->requiredScopes(), $granted));

        if ($missing !== []) {
            throw SocialIntegrationException::missingPermissions($this->label(), $missing);
        }

        $profile = $this->fetchProfile($accessToken);
        $type = strtoupper((string) ($profile['account_type'] ?? ''));

        if (! in_array($type, self::ELIGIBLE_TYPES, true)) {
            throw SocialIntegrationException::notEligible(
                $this->label(),
                'This Instagram account is a personal account. Switch it to a Business or Creator account in Instagram (Settings → Account type and tools), then connect again.',
            );
        }

        $snapshot = $this->snapshot($profile, $granted);

        return [new DiscoveredAccount($snapshot, new TokenSet(
            accessToken: $accessToken,
            expiresAt: Date::now()->addSeconds((int) $long->json('expires_in', 5184000)),
            scopes: $granted,
            providerUserId: $snapshot->externalId,
        ))];
    }

    public function refresh(SocialAccount $account, SocialToken $token): TokenSet
    {
        $response = $this->send(fn ($http) => $http->get('https://graph.instagram.com/refresh_access_token', [
            'grant_type' => 'ig_refresh_token',
            'access_token' => $token->access_token,
        ]));

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw SocialIntegrationException::providerError($this->label(), ['body' => 'missing access_token']);
        }

        return new TokenSet(
            accessToken: $accessToken,
            expiresAt: Date::now()->addSeconds((int) $response->json('expires_in', 5184000)),
            scopes: array_values((array) ($token->scopes ?? [])),
            providerUserId: $token->provider_user_id,
        );
    }

    public function inspect(SocialAccount $account, SocialToken $token): AccountSnapshot
    {
        $profile = $this->fetchProfile((string) $token->access_token);

        return $this->snapshot($profile, array_values((array) ($token->scopes ?? [])), $token->expires_at);
    }

    public function publishVideo(SocialAccount $account, SocialToken $token, VideoPublication $video): PublishedVideo
    {
        $igId = (string) $account->external_id;
        $bearer = (string) $token->access_token;

        // 1. Create a Reels container; Instagram downloads the video from our URL.
        $container = $this->send(fn ($http) => $http->withToken($bearer)->asForm()->post($this->graph("{$igId}/media"), [
            'media_type' => 'REELS',
            'video_url' => $video->videoUrl,
            'caption' => $video->caption(),
            'share_to_feed' => 'true',
        ]))->json('id');

        if (! is_string($container) || $container === '') {
            throw SocialIntegrationException::providerError($this->label(), ['body' => 'missing container id']);
        }

        // 2. Wait for processing to finish.
        $attempts = (int) $this->config('publish.poll_attempts', 24);
        $seconds = (int) $this->config('publish.poll_seconds', 5);

        for ($i = 0; $i < $attempts; $i++) {
            $status = $this->send(fn ($http) => $http->withToken($bearer)->get($this->graph($container), ['fields' => 'status_code']))
                ->json('status_code');

            if ($status === 'FINISHED') {
                break;
            }

            if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                throw SocialIntegrationException::providerError(
                    $this->label(),
                    ['status_code' => $status],
                    'Instagram could not process the video. Check that it is an MP4 within Instagram\'s Reels limits and try again.',
                );
            }

            if ($i === $attempts - 1) {
                throw SocialIntegrationException::providerError($this->label(), ['status_code' => $status], 'Instagram is still processing the video. Try publishing again in a few minutes.');
            }

            Sleep::for($seconds)->seconds();
        }

        // 3. Publish the container.
        $mediaId = $this->send(fn ($http) => $http->withToken($bearer)->asForm()->post($this->graph("{$igId}/media_publish"), [
            'creation_id' => $container,
        ]))->json('id');

        if (! is_string($mediaId) || $mediaId === '') {
            throw SocialIntegrationException::providerError($this->label(), ['body' => 'missing media id']);
        }

        $permalink = $this->send(fn ($http) => $http->withToken($bearer)->get($this->graph($mediaId), ['fields' => 'permalink']))
            ->json('permalink');

        return new PublishedVideo($mediaId, is_string($permalink) ? $permalink : null);
    }

    private function graph(string $path): string
    {
        return 'https://graph.instagram.com/'.$this->config('graph_version').'/'.ltrim($path, '/');
    }

    /** @return array<string, mixed> */
    private function fetchProfile(string $accessToken): array
    {
        $profile = $this->send(fn ($http) => $http->withToken($accessToken)->get($this->graph('me'), ['fields' => self::PROFILE_FIELDS]))->json();

        return is_array($profile) ? $profile : [];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  list<string>  $granted
     */
    private function snapshot(array $profile, array $granted, ?CarbonInterface $expiresAt = null): AccountSnapshot
    {
        $id = (string) ($profile['user_id'] ?? $profile['id'] ?? '');

        if ($id === '') {
            throw SocialIntegrationException::providerError($this->label(), ['body' => 'missing user_id']);
        }

        $username = isset($profile['username']) ? (string) $profile['username'] : null;

        return new AccountSnapshot(
            externalId: $id,
            name: (string) ($profile['name'] ?? $username ?? $id),
            username: $username,
            avatarUrl: $profile['profile_picture_url'] ?? null,
            capabilities: ['can_publish_video' => in_array('instagram_business_content_publish', $granted, true), 'can_publish_reels' => true],
            profile: [
                'account_type' => $profile['account_type'] ?? null,
                'followers' => $profile['followers_count'] ?? null,
                'following' => $profile['follows_count'] ?? null,
                'media_count' => $profile['media_count'] ?? null,
            ],
            grantedScopes: $granted,
            tokenExpiresAt: $expiresAt,
        );
    }

    /** @return list<string>|null null when the provider did not report permissions */
    private function parsePermissions(mixed $value): ?array
    {
        if (is_string($value) && $value !== '') {
            $value = explode(',', $value);
        }

        if (! is_array($value) || $value === []) {
            return null;
        }

        return array_values(array_filter(array_map('trim', array_filter($value, 'is_string')), fn (string $p): bool => $p !== ''));
    }
}
