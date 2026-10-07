<?php

namespace App\Models;

use App\Core\Model;

class Transaction extends Model
{
    public static string $table = 'transactions';
    public static array $casts = ['gateway_response' => 'array', 'amount' => 'float'];
    public static array $fillable = [
        'user_id', 'order_id', 'reference', 'amount', 'type', 'status',
        'gateway', 'gateway_reference', 'gateway_response', 'description',
    ];

    protected static function relations(): array
    {
        return [
            'user'  => ['belongsTo', User::class, 'user_id', 'id'],
            'order' => ['belongsTo', Order::class, 'order_id', 'id'],
        ];
    }
}
