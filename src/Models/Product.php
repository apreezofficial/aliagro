<?php

namespace App\Models;

use App\Core\Model;

class Product extends Model
{
    public static string $table = 'products';
    public static bool $softDeletes = true;
    public static array $casts = [
        'images' => 'array', 'is_featured' => 'bool', 'is_organic' => 'bool',
        'price' => 'float', 'discount_price' => 'float', 'rating' => 'float',
    ];
    public static array $fillable = [
        'farmer_id', 'category_id', 'name', 'slug', 'description', 'price', 'discount_price', 'unit',
        'quantity_available', 'minimum_order', 'images', 'thumbnail', 'status', 'is_featured', 'is_organic',
        'harvest_date', 'expiry_date', 'location', 'rating', 'rating_count', 'total_sold', 'views',
    ];

    protected static function relations(): array
    {
        return [
            'farmer'      => ['belongsTo', User::class, 'farmer_id', 'id'],
            'category'    => ['belongsTo', Category::class, 'category_id', 'id'],
            'reviews'     => ['hasMany', Review::class, 'product_id', 'id'],
            'order_items' => ['hasMany', OrderItem::class, 'product_id', 'id'],
        ];
    }

    public static function effectivePrice(array $p): float
    {
        return (float) ($p['discount_price'] ?? $p['price']);
    }

    public static function isInStock(array $p): bool
    {
        return $p['quantity_available'] > 0 && $p['status'] === 'active';
    }
}
