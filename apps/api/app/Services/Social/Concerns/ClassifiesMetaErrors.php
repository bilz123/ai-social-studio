<?php

namespace App\Services\Social\Concerns;

use App\Exceptions\SocialIntegrationException;
use Illuminate\Http\Client\Response;

/**
 * Error mapping shared by the Facebook and Instagram services (both use the Meta Graph error
 * format: {"error": {"message", "type", "code", "error_subcode"}}).
 *
 * @see https://developers.facebook.com/docs/graph-api/guides/error-handling
 */
trait ClassifiesMetaErrors
{
    protected function classify(Response $response): SocialIntegrationException
    {
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];

        $code = isset($error['code']) ? (int) $error['code'] : null;
        $message = $error['message'] ?? $response->json('error_message');

        $this->logProviderError($response, $code !== null ? (string) $code : null, is_string($message) ? $message : null);

        // Instagram's token endpoint answers {"error_type", "code", "error_message"}.
        if ($error === [] && $response->json('error_type') !== null) {
            return SocialIntegrationException::authorizationFailed($this->label());
        }

        return match (true) {
            $response->status() === 429,
            in_array($code, [4, 17, 32, 613], true),
            $code !== null && $code >= 80000 && $code <= 80014 => SocialIntegrationException::rateLimited($this->label()),
            in_array($code, [102, 190, 463, 467], true) => SocialIntegrationException::tokenRejected($this->label()),
            $code === 10, $code !== null && $code >= 200 && $code <= 299 => SocialIntegrationException::permissionDenied($this->label()),
            $response->serverError() => SocialIntegrationException::unavailable($this->label()),
            default => SocialIntegrationException::providerError($this->label(), ['code' => $code]),
        };
    }
}
