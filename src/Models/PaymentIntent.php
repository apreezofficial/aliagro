<?php

namespace App\Models;

use App\Core\Model;

class PaymentIntent extends Model
{
    public static string $table = 'payment_intents';
    public static array $casts = ['gateway_response' => 'array', 'paid_at' => 'datetime', 'amount' => 'float'];
    public static array $fillable = [
        'user_id', 'order_id', 'reference', 'amount', 'gateway', 'purpose', 'status',
        'authorization_url', 'gateway_response', 'paid_at',
    ];

    protected static function relations(): array
    {
        return [
            'user'  => ['belongsTo', User::class, 'user_id', 'id'],
            'order' => ['belongsTo', Order::class, 'order_id', 'id'],
        ];
    }
}
