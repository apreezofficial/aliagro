<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderDeliveredNotification extends Notification implements ShouldQueue
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
            ->subject("Order Delivered – {$this->order->order_number} | AliAgro")
            ->greeting("Hello {$notifiable->name}!")
            ->line("Your order **{$this->order->order_number}** has been delivered successfully. Enjoy your fresh produce!")
            ->line("Earned loyalty points for this order have been added to your account.")
            ->action('Leave a Review', config('app.frontend_url') . '/orders/' . $this->order->id . '/review')
            ->line('Thank you for choosing AliAgro. See you next time! 🌾');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'         => 'order_delivered',
            'order_id'     => $this->order->id,
            'order_number' => $this->order->order_number,
            'message'      => "Your order {$this->order->order_number} has been delivered.",
        ];
    }
}
