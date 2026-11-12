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
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Facebook Login for Pages.
 *
 * The user signs in with Facebook and picks the Pages to share. The authorization code becomes a
 * short-lived user token, then a long-lived one; `GET /me/accounts` with that token returns each
 * Page together with its own access token. Page tokens issued from a long-lived user token do not
 * expire, so we keep only those (the user token is discarded) and verify them with `debug_token`.
 */
class FacebookService extends AbstractSocialService implements PublishesVideo
{
    use ClassifiesMetaErrors;

    protected function configKey(): string
    {
        return 'facebook';
    }

    public function supportsRefresh(): bool
    {
        return false;
    }

    public function authorizationUrl(string $state, ?string $codeChallenge = null): string
    {
        $this->assertConfigured();

        return 'https://www.facebook.com/'.$this->config('graph_version').'/dialog/oauth?'.http_build_query([
            'client_id' => $this->config('client_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'response_type' => 'code',
            'scope' => implode(',', $this->requiredScopes()),
            // Ask again for permissions the user declined earlier instead of silently skipping them.
            'auth_type' => 'rerequest',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function completeAuthorization(string $code, ?string $codeVerifier = null): array
    {
        $this->assertConfigured();

        try {
            $short = $this->tokenRequest([
                'client_id' => $this->config('client_id'),
                'client_secret' => $this->config('client_secret'),
                'redirect_uri' => $this->redirectUri(),
                'code' => $code,
            ]);
            $userToken = $this->tokenRequest([
                'grant_type' => 'fb_exchange_token',
                'client_id' => $this->config('client_id'),
                'client_secret' => $this->config('client_secret'),
                'fb_exchange_token' => $short,
            ]);
        } catch (SocialIntegrationException $e) {
            // A provider-side rejection at this step means the code is unusable, not that we failed.
            throw $e->errorCode === 'provider_error' ? SocialIntegrationException::authorizationFailed($this->label()) : $e;
        }

        $granted = $this->grantedPermissions($userToken);
        $missing = array_values(array_diff($this->requiredScopes(), $granted));

        if ($missing !== []) {
            throw SocialIntegrationException::missingPermissions($this->label(), $missing);
        }

        $pages = $this->send(fn ($http) => $http->withToken($userToken)->get($this->graph('me/accounts'), [
            'fields' => 'id,name,username,category,fan_count,tasks,access_token,picture.type(large){url}',
            'limit' => 100,
        ]))->json('data', []);

        $discovered = [];

        foreach (is_array($pages) ? $pages : [] as $page) {
            if (! isset($page['id'], $page['name'], $page['access_token'])) {
                // No page token means the user has no task on this Page that allows posting.
                continue;
            }

            $tasks = array_values(array_filter((array) ($page['tasks'] ?? []), 'is_string'));

            $discovered[] = new DiscoveredAccount(
                new AccountSnapshot(
                    externalId: (string) $page['id'],
                    name: (string) $page['name'],
                    username: isset($page['username']) ? (string) $page['username'] : null,
                    avatarUrl: $page['picture']['data']['url'] ?? null,
                    capabilities: ['can_publish_video' => in_array('CREATE_CONTENT', $tasks, true)],
                    profile: ['category' => $page['category'] ?? null, 'followers' => $page['fan_count'] ?? null],
                    grantedScopes: $granted,
                ),
                new TokenSet(
                    accessToken: (string) $page['access_token'],
                    expiresAt: null,
                    scopes: $granted,
                    providerUserId: (string) $page['id'],
                ),
            );
        }

        if ($discovered === []) {
            throw SocialIntegrationException::notEligible(
                $this->label(),
                'No Facebook Pages were shared with the app. Connect again, choose at least one Page you manage, and make sure you can create content on it.',
            );
        }

        return $discovered;
    }

    public function refresh(SocialAccount $account, SocialToken $token): TokenSet
    {
        // Page tokens do not expire; when one stops working only the user can issue a new one.
        throw SocialIntegrationException::reconnectRequired($this->label());
    }

    public function inspect(SocialAccount $account, SocialToken $token): AccountSnapshot
    {
        $this->assertConfigured();

        $debug = $this->send(fn ($http) => $http->get($this->graph('debug_token'), [
            'input_token' => $token->access_token,
            'access_token' => $this->config('client_id').'|'.$this->config('client_secret'),
        ]))->json('data', []);

        if (! is_array($debug) || ($debug['is_valid'] ?? false) !== true) {
            throw SocialIntegrationException::tokenRejected($this->label());
        }

        $page = $this->send(fn ($http) => $http->withToken($token->access_token)->get($this->graph((string) $account->external_id), [
            'fields' => 'id,name,username,category,fan_count,picture.type(large){url}',
        ]));

        $expires = (int) ($debug['expires_at'] ?? 0);

        return new AccountSnapshot(
            externalId: (string) $account->external_id,
            name: (string) ($page->json('name') ?? $account->name),
            username: $page->json('username'),
            avatarUrl: $page->json('picture.data.url'),
            capabilities: (array) ($account->capabilities ?? []),
            profile: ['category' => $page->json('category'), 'followers' => $page->json('fan_count')],
            grantedScopes: array_values(array_filter((array) ($debug['scopes'] ?? []), 'is_string')),
            tokenExpiresAt: $expires > 0 ? CarbonImmutable::createFromTimestamp($expires) : null,
        );
    }

    public function publishVideo(SocialAccount $account, SocialToken $token, VideoPublication $video): PublishedVideo
    {
        $response = $this->send(fn ($http) => $http->withToken($token->access_token)->asForm()->post(
            $this->graph("{$account->external_id}/videos"),
            [
                'file_url' => $video->videoUrl,
                'title' => Str::limit($video->title, 255, ''),
                'description' => $video->caption(),
                'published' => 'true',
            ],
        ));

        $id = $response->json('id');

        if (! is_string($id) || $id === '') {
            throw SocialIntegrationException::providerError($this->label(), ['body' => 'missing video id']);
        }

        return new PublishedVideo($id, "https://www.facebook.com/watch/?v={$id}");
    }

    private function graph(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->config('graph_version').'/'.ltrim($path, '/');
    }

    /** @param  array<string, mixed>  $form */
    private function tokenRequest(array $form): string
    {
        $token = $this->send(fn ($http) => $http->asForm()->post($this->graph('oauth/access_token'), $form))->json('access_token');

        if (! is_string($token) || $token === '') {
            throw SocialIntegrationException::authorizationFailed($this->label());
        }

        return $token;
    }

    /** @return list<string> */
    private function grantedPermissions(string $userToken): array
    {
        $rows = $this->send(fn ($http) => $http->withToken($userToken)->get($this->graph('me/permissions')))->json('data', []);

        $granted = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (($row['status'] ?? null) === 'granted' && is_string($row['permission'] ?? null)) {
                $granted[] = $row['permission'];
            }
        }

        return $granted;
    }
}
