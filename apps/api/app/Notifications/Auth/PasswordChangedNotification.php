<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/** Security alert: tells the owner their password was changed so a hijack is noticed quickly. */
class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your password was changed')
            ->line('The password for your '.Config::get('app.name').' account was just changed.')
            ->line('If this was you, no further action is needed.')
            ->line('If it was not you, reset your password immediately and contact support.')
            ->action('Reset password', Config::get('api.frontend_url').'/forgot-password');
    }
}
