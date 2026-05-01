<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderShippedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Order $order) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your Order is On Its Way! – {$this->order->order_number} | AliAgro")
            ->greeting("Great news, {$notifiable->name}!")
            ->line("Your order **{$this->order->order_number}** has been shipped and is on its way to you.")
            ->line("**Delivery address:** {$this->order->delivery_address}, {$this->order->delivery_state}")
            ->line("**Contact phone:** {$this->order->delivery_phone}")
            ->action('Track Order', config('app.frontend_url') . '/orders/' . $this->order->id)
            ->line('Fresh produce is on its way — AliAgro 🌿');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'         => 'order_shipped',
            'order_id'     => $this->order->id,
            'order_number' => $this->order->order_number,
            'message'      => "Your order {$this->order->order_number} has been shipped.",
        ];
    }
}
