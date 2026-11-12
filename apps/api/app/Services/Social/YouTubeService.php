<?php

namespace App\Services\Social;

use App\Contracts\Social\PublishesVideo;
use App\Exceptions\SocialIntegrationException;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Services\Social\Data\AccountSnapshot;
use App\Services\Social\Data\DiscoveredAccount;
use App\Services\Social\Data\PublishedVideo;
use App\Services\Social\Data\TokenSet;
use App\Services\Social\Data\VideoPublication;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * YouTube through Google OAuth 2.0 (web server flow with PKCE).
 *
 * `access_type=offline` + `prompt=consent` makes Google return a refresh token every time; access
 * tokens last about an hour and are renewed with it. Google's granular consent lets the user
 * untick scopes, so the granted `scope` of the token response is verified, not assumed.
 *
 * Note: while the Google Cloud OAuth app is in "Testing" status, refresh tokens expire after
 * 7 days. Publish the app (and complete verification for the sensitive upload scope) for real use.
 */
class YouTubeService extends AbstractSocialService implements PublishesVideo
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    private const API = 'https://www.googleapis.com/youtube/v3';

    private const UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos';

    protected function configKey(): string
    {
        return 'youtube';
    }

    public function usesPkce(): bool
    {
        return true;
    }

    public function supportsRefresh(): bool
    {
        return true;
    }

    public function authorizationUrl(string $state, ?string $codeChallenge = null): string
    {
        $this->assertConfigured();

        $query = [
            'client_id' => $this->config('client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', $this->requiredScopes()),
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];

        if ($codeChallenge !== null) {
            $query['code_challenge'] = $codeChallenge;
            $query['code_challenge_method'] = 'S256';
        }

        return self::AUTH_URL.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public function completeAuthorization(string $code, ?string $codeVerifier = null): array
    {
        $this->assertConfigured();

        $form = [
            'client_id' => $this->config('client_id'),
            'client_secret' => $this->config('client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
        ];

        if ($codeVerifier !== null) {
            $form['code_verifier'] = $codeVerifier;
        }

        try {
            $response = $this->send(fn ($http) => $http->asForm()->post(self::TOKEN_URL, $form));
        } catch (SocialIntegrationException $e) {
            // Here a rejected grant means the code was bad or already used.
            throw $e->requiresReconnect() ? SocialIntegrationException::authorizationFailed($this->label()) : $e;
        }

        $accessToken = $response->json('access_token');
        $refreshToken = $response->json('refresh_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw SocialIntegrationException::authorizationFailed($this->label());
        }

        $granted = array_values(array_filter(explode(' ', (string) $response->json('scope', ''))));
        $missing = array_values(array_diff($this->requiredScopes(), $granted));

        if ($missing !== []) {
            throw SocialIntegrationException::missingPermissions($this->label(), array_map($this->shortScope(...), $missing));
        }

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw SocialIntegrationException::authorizationFailed(
                $this->label(),
                'Google did not allow offline access. Remove this app at myaccount.google.com/permissions and connect again.',
            );
        }

        $tokens = new TokenSet(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: Date::now()->addSeconds((int) $response->json('expires_in', 3600)),
            scopes: $granted,
        );

        $channels = $this->channels($accessToken);

        if ($channels === []) {
            throw SocialIntegrationException::notEligible(
                $this->label(),
                'This Google account does not have a YouTube channel. Create a channel on youtube.com, then connect again.',
            );
        }

        return array_map(
            fn (array $channel): DiscoveredAccount => new DiscoveredAccount($this->snapshot($channel, $granted), new TokenSet(
                accessToken: $tokens->accessToken,
                refreshToken: $tokens->refreshToken,
                expiresAt: $tokens->expiresAt,
                scopes: $tokens->scopes,
                providerUserId: (string) $channel['id'],
            )),
            $channels,
        );
    }

    public function refresh(SocialAccount $account, SocialToken $token): TokenSet
    {
        $this->assertConfigured();

        if (! is_string($token->refresh_token) || $token->refresh_token === '') {
            throw SocialIntegrationException::reconnectRequired($this->label());
        }

        $response = $this->send(fn ($http) => $http->asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->config('client_id'),
            'client_secret' => $this->config('client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $token->refresh_token,
        ]));

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw SocialIntegrationException::providerError($this->label(), ['body' => 'missing access_token']);
        }

        $scopes = array_values(array_filter(explode(' ', (string) $response->json('scope', ''))));

        return new TokenSet(
            accessToken: $accessToken,
            // Google normally omits the refresh token here; the stored one stays valid.
            refreshToken: is_string($response->json('refresh_token')) ? $response->json('refresh_token') : $token->refresh_token,
            expiresAt: Date::now()->addSeconds((int) $response->json('expires_in', 3600)),
            scopes: $scopes !== [] ? $scopes : array_values((array) ($token->scopes ?? [])),
            providerUserId: $token->provider_user_id,
        );
    }

    public function inspect(SocialAccount $account, SocialToken $token): AccountSnapshot
    {
        foreach ($this->channels((string) $token->access_token) as $channel) {
            if ((string) $channel['id'] === (string) $account->external_id) {
                return $this->snapshot($channel, array_values((array) ($token->scopes ?? [])), $token->expires_at);
            }
        }

        throw SocialIntegrationException::accountUnavailable(
            $this->label(),
            'This YouTube channel is no longer available to the connected Google account. Reconnect to choose a channel.',
        );
    }

    public function revoke(SocialAccount $account, SocialToken $token): void
    {
        $value = $token->refresh_token ?: $token->access_token;

        if (! is_string($value) || $value === '') {
            return;
        }

        try {
            $this->send(fn ($http) => $http->asForm()->post(self::REVOKE_URL, ['token' => $value]));
        } catch (SocialIntegrationException) {
            // Already revoked or Google unreachable: the local credentials are deleted regardless.
        }
    }

    /**
     * Resumable upload: start a session with the metadata, then send the bytes. 10-second videos
     * are small, so the file is fetched and sent in one request; larger media should be chunked.
     */
    public function publishVideo(SocialAccount $account, SocialToken $token, VideoPublication $video): PublishedVideo
    {
        $source = $this->send(fn ($http) => $http->get($video->videoUrl));
        $bytes = $source->body();

        $session = $this->send(fn ($http) => $http->withToken((string) $token->access_token)
            ->withHeaders(['X-Upload-Content-Type' => 'video/mp4', 'X-Upload-Content-Length' => (string) strlen($bytes)])
            ->post(self::UPLOAD_URL.'?uploadType=resumable&part=snippet,status', [
                'snippet' => [
                    'title' => Str::limit($video->title, 100, ''),
                    'description' => Str::limit($video->caption(), 5000, ''),
                    'tags' => array_values($video->tags),
                ],
                'status' => [
                    'privacyStatus' => in_array($video->privacy, ['private', 'unlisted', 'public'], true) ? $video->privacy : 'private',
                    'selfDeclaredMadeForKids' => false,
                ],
            ]));

        $location = $session->header('Location');

        if ($location === '') {
            throw SocialIntegrationException::providerError($this->label(), ['body' => 'missing upload session']);
        }

        $uploaded = $this->send(fn ($http) => $http->withToken((string) $token->access_token)
            ->withBody($bytes, 'video/mp4')
            ->put($location));

        $id = $uploaded->json('id');

        if (! is_string($id) || $id === '') {
            throw SocialIntegrationException::providerError($this->label(), ['body' => 'missing video id']);
        }

        return new PublishedVideo($id, "https://www.youtube.com/watch?v={$id}");
    }

    protected function classify(Response $response): SocialIntegrationException
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $error = $body['error'] ?? null;

        if (is_string($error)) {
            // OAuth endpoints: {"error": "invalid_grant", "error_description": "..."}
            $reason = $error;
            $message = $body['error_description'] ?? null;
        } elseif (is_array($error)) {
            // API endpoints: {"error": {"code", "message", "errors": [{"reason"}], "status"}}
            $reason = $error['errors'][0]['reason'] ?? $error['status'] ?? null;
            $message = $error['message'] ?? null;
        } else {
            $reason = $message = null;
        }

        $this->logProviderError($response, is_string($reason) ? $reason : null, is_string($message) ? $message : null);

        return match (true) {
            in_array($reason, ['invalid_client', 'unauthorized_client'], true) => SocialIntegrationException::providerError(
                $this->label(),
                ['reason' => $reason],
                'Google rejected this application\'s credentials. An administrator must check the Google client ID and secret.',
            ),
            in_array($reason, ['invalid_grant', 'invalid_token', 'authError'], true), $response->status() === 401 => SocialIntegrationException::tokenRejected($this->label()),
            in_array($reason, ['quotaExceeded', 'rateLimitExceeded', 'userRateLimitExceeded', 'dailyLimitExceeded', 'uploadLimitExceeded', 'RESOURCE_EXHAUSTED'], true), $response->status() === 429 => SocialIntegrationException::rateLimited($this->label()),
            in_array($reason, ['insufficientPermissions', 'forbidden', 'PERMISSION_DENIED', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT'], true) => SocialIntegrationException::permissionDenied($this->label()),
            $reason === 'youtubeSignupRequired' => SocialIntegrationException::notEligible($this->label(), 'This Google account does not have a YouTube channel yet.'),
            $response->serverError() => SocialIntegrationException::unavailable($this->label()),
            default => SocialIntegrationException::providerError($this->label(), ['reason' => $reason]),
        };
    }

    /** @return list<array<string, mixed>> */
    private function channels(string $accessToken): array
    {
        $items = $this->send(fn ($http) => $http->withToken($accessToken)->get(self::API.'/channels', [
            'part' => 'snippet,statistics',
            'mine' => 'true',
            'maxResults' => 50,
        ]))->json('items', []);

        return array_values(array_filter(is_array($items) ? $items : [], fn ($item): bool => is_array($item) && isset($item['id'])));
    }

    /**
     * @param  array<string, mixed>  $channel
     * @param  list<string>  $granted
     */
    private function snapshot(array $channel, array $granted, ?CarbonInterface $expiresAt = null): AccountSnapshot
    {
        $snippet = (array) ($channel['snippet'] ?? []);
        $stats = (array) ($channel['statistics'] ?? []);
        $thumbs = (array) ($snippet['thumbnails'] ?? []);
        $hidden = (bool) ($stats['hiddenSubscriberCount'] ?? false);

        return new AccountSnapshot(
            externalId: (string) $channel['id'],
            name: (string) ($snippet['title'] ?? $channel['id']),
            username: isset($snippet['customUrl']) ? (string) $snippet['customUrl'] : null,
            avatarUrl: $thumbs['medium']['url'] ?? $thumbs['default']['url'] ?? null,
            capabilities: [
                'can_upload_video' => in_array('https://www.googleapis.com/auth/youtube.upload', $granted, true),
                'uploads_per_day' => (int) $this->config('uploads_per_day', 100),
            ],
            profile: [
                'subscribers' => $hidden || ! isset($stats['subscriberCount']) ? null : (int) $stats['subscriberCount'],
                'videos' => isset($stats['videoCount']) ? (int) $stats['videoCount'] : null,
                'views' => isset($stats['viewCount']) ? (int) $stats['viewCount'] : null,
                'country' => $snippet['country'] ?? null,
            ],
            grantedScopes: $granted,
            tokenExpiresAt: $expiresAt,
        );
    }

    private function shortScope(string $scope): string
    {
        return Str::afterLast($scope, '/');
    }
}
