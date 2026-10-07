<?php

namespace App\Notifications;

use App\Core\MailMessage;
use App\Core\Notification;

class OrderDeliveredNotification extends Notification
{
    public function __construct(private array $order) {}

    public function toMail(array $notifiable): MailMessage
    {
        $o = $this->order;

        return (new MailMessage())
            ->subject("Order Delivered – {$o['order_number']} | AliAgro")
            ->greeting("Hello {$notifiable['name']}!")
            ->line("Your order **{$o['order_number']}** has been delivered successfully. Enjoy your fresh produce!")
            ->line('Earned loyalty points for this order have been added to your account.')
            ->action('Leave a Review', config('app.frontend_url') . '/orders/' . $o['id'] . '/review')
            ->line('Thank you for choosing AliAgro. See you next time! 🌾');
    }

    public function toArray(array $notifiable): array
    {
        return [
            'type'         => 'order_delivered',
            'order_id'     => $this->order['id'],
            'order_number' => $this->order['order_number'],
            'message'      => "Your order {$this->order['order_number']} has been delivered.",
        ];
    }
}
