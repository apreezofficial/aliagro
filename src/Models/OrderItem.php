<?php

namespace App\Models;

use App\Core\Model;

class OrderItem extends Model
{
    public static string $table = 'order_items';
    public static array $casts = ['unit_price' => 'float', 'subtotal' => 'float'];
    public static array $fillable = ['order_id', 'product_id', 'farmer_id', 'product_name', 'unit_price', 'quantity', 'unit', 'subtotal', 'status'];

    protected static function relations(): array
    {
        return [
            'order'   => ['belongsTo', Order::class, 'order_id', 'id'],
            'product' => ['belongsTo', Product::class, 'product_id', 'id'],
            'farmer'  => ['belongsTo', User::class, 'farmer_id', 'id'],
        ];
    }
}
