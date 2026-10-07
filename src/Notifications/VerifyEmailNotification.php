<?php

namespace App\Notifications;

use App\Core\Auth;
use App\Core\MailMessage;
use App\Core\Notification;

/** Laravel's default "Verify Email Address" mail, with a 60 minute signed link. */
class VerifyEmailNotification extends Notification
{
    public function via(array $notifiable): array
    {
        return ['mail'];
    }

    public static function verificationUrl(array $user): string
    {
        $url = config('app.url') . '/api/auth/verify/' . $user['id'] . '/' . sha1($user['email']);
        return Auth::signUrl($url, time() + 3600);
    }

    public function toMail(array $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Verify Email Address')
            ->line('Please click the button below to verify your email address.')
            ->action('Verify Email Address', self::verificationUrl($notifiable))
            ->line('If you did not create an account, no further action is required.');
    }

    public function toArray(array $notifiable): array
    {
        return [];
    }
}
