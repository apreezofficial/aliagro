<?php

namespace App\Models;

use App\Core\Model;
use App\Core\Str;

class Order extends Model
{
    public static string $table = 'orders';
    public static bool $softDeletes = true;
    public static array $casts = [
        'paid_at' => 'datetime', 'delivered_at' => 'datetime',
        'subtotal' => 'float', 'delivery_fee' => 'float', 'discount' => 'float', 'total' => 'float',
    ];
    public static array $fillable = [
        'order_number', 'consumer_id', 'subtotal', 'delivery_fee', 'discount', 'total', 'status', 'payment_status',
        'payment_method', 'payment_reference', 'delivery_address', 'delivery_state', 'delivery_lga',
        'delivery_phone', 'notes', 'paid_at', 'delivered_at',
    ];

    protected static function relations(): array
    {
        return [
            'consumer' => ['belongsTo', User::class, 'consumer_id', 'id'],
            'items'    => ['hasMany', OrderItem::class, 'order_id', 'id'],
        ];
    }

    public static function generateOrderNumber(): string
    {
        return 'ALG-' . Str::uniqueId();
    }
}
