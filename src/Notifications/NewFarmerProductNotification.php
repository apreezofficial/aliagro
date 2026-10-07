<?php

namespace App\Notifications;

use App\Core\MailMessage;
use App\Core\Notification;

class NewFarmerProductNotification extends Notification
{
    public function __construct(private array $product, private array $farmer) {}

    public function toMail(array $notifiable): MailMessage
    {
        $p = $this->product;
        $f = $this->farmer;

        return (new MailMessage())
            ->subject("{$f['name']} just listed a new product | AliAgro")
            ->greeting("Hello {$notifiable['name']}!")
            ->line("A farmer you follow, **{$f['name']}**, just listed a new product:")
            ->line("**{$p['name']}** — ₦" . number_format((float) $p['price'], 2) . " per {$p['unit']}")
            ->action('View Product', config('app.frontend_url') . '/products/' . $p['id'])
            ->line('Shop fresh, shop direct — AliAgro 🌿');
    }

    public function toArray(array $notifiable): array
    {
        return [
            'type'        => 'new_farmer_product',
            'product_id'  => $this->product['id'],
            'farmer_id'   => $this->farmer['id'],
            'farmer_name' => $this->farmer['name'],
            'message'     => "{$this->farmer['name']} listed a new product: {$this->product['name']}",
        ];
    }
}
