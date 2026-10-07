<?php

namespace App\Core;

/**
 * Base class for notifications. send() stores the database notification and
 * emails the user. Failures are logged and swallowed: a flaky SMTP server must
 * never turn a successful order/payment into a 500.
 *
 * Unlike the Laravel version there is no queue worker, so mail is sent inline.
 */
abstract class Notification
{
    abstract public function toMail(array $notifiable): MailMessage;

    abstract public function toArray(array $notifiable): array;

    /** Channels; subclasses may narrow to ['database'] or ['mail']. */
    public function via(array $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function send(array $notifiable): void
    {
        $channels = $this->via($notifiable);

        if (in_array('database', $channels, true)) {
            try {
                $ts = now();
                DB::insert('notifications', [
                    'id'              => Str::uuid(),
                    'type'            => 'App\\Notifications\\' . (new \ReflectionClass($this))->getShortName(),
                    'notifiable_type' => 'App\\Models\\User',
                    'notifiable_id'   => $notifiable['id'],
                    'data'            => json_encode($this->toArray($notifiable), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at'      => $ts,
                    'updated_at'      => $ts,
                ]);
            } catch (\Throwable $e) {
                Logger::error('Failed to store notification', ['error' => $e->getMessage(), 'type' => static::class]);
            }
        }

        if (in_array('mail', $channels, true) && !empty($notifiable['email'])) {
            try {
                Mailer::send($notifiable['email'], (string) ($notifiable['name'] ?? ''), $this->toMail($notifiable));
            } catch (\Throwable $e) {
                Logger::error('Failed to send mail', ['error' => $e->getMessage(), 'to' => $notifiable['email'], 'type' => static::class]);
            }
        }
    }
}
