<?php

namespace App\Models;

use App\Core\Model;

class CartItem extends Model
{
    public static string $table = 'cart_items';
    public static array $fillable = ['cart_id', 'product_id', 'quantity'];

    protected static function relations(): array
    {
        return [
            'cart'    => ['belongsTo', Cart::class, 'cart_id', 'id'],
            'product' => ['belongsTo', Product::class, 'product_id', 'id'],
        ];
    }
}
