<?php

namespace App\Services\Social;

use App\Contracts\Social\SocialIntegration;
use App\Enums\SocialPlatform;
use App\Exceptions\SocialIntegrationException;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use LogicException;

/**
 * Shared plumbing for the platform services: configuration lookup, one HTTP client with timeouts
 * and connection retries, and a single place where provider failures become
 * SocialIntegrationException. Tokens are sent in Authorization headers wherever the provider
 * allows it so they stay out of URLs.
 */
abstract class AbstractSocialService implements SocialIntegration
{
    /** The key under config('social.providers'): "facebook", "instagram" or "youtube". */
    abstract protected function configKey(): string;

    /** Turn a failed provider response into the right user-facing exception. */
    abstract protected function classify(Response $response): SocialIntegrationException;

    public function label(): string
    {
        return (string) $this->config('label');
    }

    public function platform(): SocialPlatform
    {
        return SocialPlatform::fromSlug($this->configKey()) ?? throw new LogicException('Unknown platform.');
    }

    public function isConfigured(): bool
    {
        return $this->missingConfiguration() === [];
    }

    public function missingConfiguration(): array
    {
        $missing = [];

        foreach ((array) $this->config('env', []) as $key => $envName) {
            if (! is_string($this->config($key)) || trim((string) $this->config($key)) === '') {
                $missing[] = (string) $envName;
            }
        }

        return $missing;
    }

    public function requiredScopes(): array
    {
        return array_values((array) $this->config('scopes', []));
    }

    public function usesPkce(): bool
    {
        return false;
    }

    public function revoke(SocialAccount $account, SocialToken $token): void
    {
        // Most providers have no revocation endpoint for this kind of token.
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config("social.providers.{$this->configKey()}.{$key}", $default);
    }

    /** The URL the provider sends the user back to (served by the SPA). */
    protected function redirectUri(): string
    {
        return rtrim((string) config('api.frontend_url'), '/')
            .str_replace('{platform}', $this->configKey(), (string) config('social.redirect_path'));
    }

    protected function assertConfigured(): void
    {
        $missing = $this->missingConfiguration();

        if ($missing !== []) {
            throw SocialIntegrationException::notConfigured($this->label(), $missing);
        }
    }

    protected function http(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout((int) config('social.http.timeout'))
            ->connectTimeout((int) config('social.http.connect_timeout'));
    }

    /**
     * Run a request, mapping network failures and non-2xx answers to SocialIntegrationException.
     *
     * @param  Closure(PendingRequest): Response  $request
     */
    protected function send(Closure $request): Response
    {
        // Retry only when the connection itself failed; a provider answer (even an error) is final.
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $request($this->http());
                break;
            } catch (ConnectionException) {
                if ($attempt >= 3) {
                    throw SocialIntegrationException::unavailable($this->label());
                }

                Sleep::for(250)->milliseconds();
            }
        }

        if ($response->failed()) {
            throw $this->classify($response);
        }

        return $response;
    }

    /** Record provider failure details (never secrets) for operators. */
    protected function logProviderError(Response $response, ?string $code, ?string $message): void
    {
        Log::warning('social.provider_error', [
            'platform' => $this->configKey(),
            'http_status' => $response->status(),
            'provider_code' => $code,
            'message' => $message !== null ? Str::limit($message, 200, '') : null,
        ]);
    }
}
