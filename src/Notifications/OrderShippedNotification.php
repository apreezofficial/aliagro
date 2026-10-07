<?php

namespace App\Notifications;

use App\Core\MailMessage;
use App\Core\Notification;

class OrderShippedNotification extends Notification
{
    public function __construct(private array $order) {}

    public function toMail(array $notifiable): MailMessage
    {
        $o = $this->order;

        return (new MailMessage())
            ->subject("Your Order is On Its Way! – {$o['order_number']} | AliAgro")
            ->greeting("Great news, {$notifiable['name']}!")
            ->line("Your order **{$o['order_number']}** has been shipped and is on its way to you.")
            ->line("**Delivery address:** {$o['delivery_address']}, {$o['delivery_state']}")
            ->line("**Contact phone:** {$o['delivery_phone']}")
            ->action('Track Order', config('app.frontend_url') . '/orders/' . $o['id'])
            ->line('Fresh produce is on its way — AliAgro 🌿');
    }

    public function toArray(array $notifiable): array
    {
        return [
            'type'         => 'order_shipped',
            'order_id'     => $this->order['id'],
            'order_number' => $this->order['order_number'],
            'message'      => "Your order {$this->order['order_number']} has been shipped.",
        ];
    }
}
