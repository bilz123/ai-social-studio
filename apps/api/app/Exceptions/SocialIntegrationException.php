<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A failure in a social integration. The message is written for the end user and is safe to show;
 * provider details (which may be sensitive) go in `$context` for logs only.
 */
class SocialIntegrationException extends RuntimeException
{
    /** Error codes meaning "the stored credentials are no longer usable; the user must reconnect". */
    private const RECONNECT_CODES = ['token_rejected', 'account_unavailable', 'reconnect_required'];

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $detail,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($detail, 0, $previous);
    }

    public function requiresReconnect(): bool
    {
        return in_array($this->errorCode, self::RECONNECT_CODES, true);
    }

    /** @param  list<string>  $missing */
    public static function notConfigured(string $label, array $missing = []): self
    {
        return new self(
            'integration_not_configured',
            503,
            "{$label} publishing is not set up on this server yet. An administrator needs to add the {$label} developer credentials.",
            ['missing' => $missing],
        );
    }

    public static function invalidState(): self
    {
        return new self('invalid_oauth_state', 400, 'This connection attempt has expired or was not started from this account. Start the connection again.');
    }

    public static function authorizationDenied(string $label, ?string $reason = null): self
    {
        return new self('authorization_denied', 400, "{$label} did not grant access. Connect again and approve the requested permissions.", ['reason' => $reason]);
    }

    public static function authorizationFailed(string $label, string $hint = ''): self
    {
        return new self('authorization_failed', 400, trim("{$label} rejected the sign-in. Start the connection again. {$hint}"));
    }

    /** @param  list<string>  $missing */
    public static function missingPermissions(string $label, array $missing): self
    {
        return new self(
            'missing_permissions',
            422,
            "{$label} access is missing required permissions: ".implode(', ', $missing).'. Connect again and approve every permission.',
            ['missing' => $missing],
        );
    }

    public static function permissionDenied(string $label): self
    {
        return new self('missing_permissions', 422, "{$label} says this connection lacks a permission it needs. Reconnect the account and approve every permission.");
    }

    public static function notEligible(string $label, string $detail): self
    {
        return new self('account_not_eligible', 422, $detail, ['platform' => $label]);
    }

    public static function tokenRejected(string $label): self
    {
        return new self('token_rejected', 409, "{$label} no longer accepts this connection. Reconnect the account.");
    }

    public static function accountUnavailable(string $label, string $detail): self
    {
        return new self('account_unavailable', 409, $detail, ['platform' => $label]);
    }

    public static function reconnectRequired(string $label): self
    {
        return new self('reconnect_required', 409, "{$label} connections cannot be renewed automatically. Reconnect the account.");
    }

    public static function rateLimited(string $label): self
    {
        return new self('provider_rate_limited', 429, "{$label} is limiting requests right now. Try again in a few minutes.");
    }

    public static function unavailable(string $label): self
    {
        return new self('provider_unavailable', 502, "{$label} could not be reached. Try again in a moment.");
    }

    /** @param  array<string, mixed>  $context */
    public static function providerError(string $label, array $context = [], string $detail = ''): self
    {
        return new self('provider_error', 502, $detail !== '' ? $detail : "{$label} returned an error. Try again, and contact support if it keeps happening.", $context);
    }
}
