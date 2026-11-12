<?php

namespace App\Notifications\Auth;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * "Verify your email" mail. The link opens the SPA (/verify-email) carrying the signed query that
 * the SPA forwards to GET /api/v1/auth/verify-email/{id}/{hash}; the API re-checks the signature,
 * so the link cannot be forged or reused for another user.
 */
class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    protected function verificationUrl($notifiable): string
    {
        $signed = URL::temporarySignedRoute(
            'v1.auth.verification.verify',
            Carbon::now()->addMinutes((int) Config::get('auth.verification.expire', 60)),
            ['id' => $notifiable->getRouteKey(), 'hash' => sha1($notifiable->getEmailForVerification())],
            absolute: false,
        );

        parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

        return Config::get('api.frontend_url').'/verify-email?'.http_build_query([
            'id' => $notifiable->getRouteKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
            'expires' => $query['expires'] ?? '',
            'signature' => $query['signature'] ?? '',
        ]);
    }
}
