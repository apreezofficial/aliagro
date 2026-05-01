<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewFarmerProductNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Product $product, public User $farmer) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->farmer->name} just listed a new product | AliAgro")
            ->greeting("Hello {$notifiable->name}!")
            ->line("A farmer you follow, **{$this->farmer->name}**, just listed a new product:")
            ->line("**{$this->product->name}** — ₦" . number_format($this->product->price, 2) . " per {$this->product->unit}")
            ->action('View Product', config('app.frontend_url') . '/products/' . $this->product->id)
            ->line('Shop fresh, shop direct — AliAgro 🌿');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'       => 'new_farmer_product',
            'product_id' => $this->product->id,
            'farmer_id'  => $this->farmer->id,
            'farmer_name'=> $this->farmer->name,
            'message'    => "{$this->farmer->name} listed a new product: {$this->product->name}",
        ];
    }
}
