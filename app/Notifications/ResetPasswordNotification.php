<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The password reset email: queued, in the user's own language (User::preferredLocale, applied by the notification sender, also when
 * the job runs later), with the Lamma mail theme. Same token and link as Laravel's own ResetPassword.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Reset your Lamma password'))
            ->greeting(__('Reset your password'))
            ->line(__('We got a request to reset the password of your Lamma account.'))
            ->action(__('Reset password'), $url)
            ->line(__('This link works for :count minutes.', ['count' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]))
            ->line(__('If you did not ask for this, you can ignore this email. Nothing changes.'))
            ->salutation(__('See you at the next game, Lamma'));
    }
}
