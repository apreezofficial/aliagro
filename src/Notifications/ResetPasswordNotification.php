<?php

namespace App\Notifications;

use App\Core\MailMessage;
use App\Core\Notification;

/** Points the user at the SPA's reset page: {FRONTEND_URL}/reset-password?token=...&email=... */
class ResetPasswordNotification extends Notification
{
    public function __construct(private string $token) {}

    public function via(array $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(array $notifiable): MailMessage
    {
        $url = config('app.frontend_url') . '/reset-password?' . http_build_query([
            'token' => $this->token,
            'email' => $notifiable['email'],
        ]);

        return (new MailMessage())
            ->subject('Reset Password Notification')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->action('Reset Password', $url)
            ->line('This password reset link will expire in 60 minutes.')
            ->line('If you did not request a password reset, no further action is required.');
    }

    public function toArray(array $notifiable): array
    {
        return [];
    }
}
