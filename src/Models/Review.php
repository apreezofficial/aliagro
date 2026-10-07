<?php

namespace App\Models;

use App\Core\Model;

class Review extends Model
{
    public static string $table = 'reviews';
    public static array $casts = ['images' => 'array', 'is_verified_purchase' => 'bool'];
    public static array $fillable = ['product_id', 'consumer_id', 'order_id', 'rating', 'comment', 'images', 'is_verified_purchase'];

    protected static function relations(): array
    {
        return [
            'product'  => ['belongsTo', Product::class, 'product_id', 'id'],
            'consumer' => ['belongsTo', User::class, 'consumer_id', 'id'],
            'order'    => ['belongsTo', Order::class, 'order_id', 'id'],
        ];
    }
}
