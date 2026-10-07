<?php

namespace App\Models;

use App\Core\Model;

class ProductView extends Model
{
    public static string $table = 'product_views';
    public static array $casts = ['last_viewed_at' => 'datetime'];
    public static array $fillable = ['user_id', 'product_id', 'view_count', 'last_viewed_at'];

    protected static function relations(): array
    {
        return ['product' => ['belongsTo', Product::class, 'product_id', 'id']];
    }
}
