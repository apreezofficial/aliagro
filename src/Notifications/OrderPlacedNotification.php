<?php

namespace App\Notifications;

use App\Core\MailMessage;
use App\Core\Notification;
use App\Models\OrderItem;

class OrderPlacedNotification extends Notification
{
    public function __construct(private array $order) {}

    public function toMail(array $notifiable): MailMessage
    {
        $o     = $this->order;
        $count = OrderItem::where('order_id', $o['id'])->count();

        return (new MailMessage())
            ->subject("Order Confirmed – {$o['order_number']} | AliAgro")
            ->greeting("Hello {$notifiable['name']}!")
            ->line("Your order **{$o['order_number']}** has been placed successfully.")
            ->line('**Total:** ₦' . number_format((float) $o['total'], 2))
            ->line("**Items:** {$count} item(s)")
            ->line("**Delivery to:** {$o['delivery_address']}, {$o['delivery_state']}")
            ->action('View Order', config('app.frontend_url') . '/orders/' . $o['id'])
            ->line('Thank you for shopping with AliAgro — fresh from the farm to your door!');
    }

    public function toArray(array $notifiable): array
    {
        return [
            'type'         => 'order_placed',
            'order_id'     => $this->order['id'],
            'order_number' => $this->order['order_number'],
            'total'        => $this->order['total'],
            'message'      => "Your order {$this->order['order_number']} has been placed.",
        ];
    }
}
