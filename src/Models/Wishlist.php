<?php

namespace App\Models;

use App\Core\Model;

class Wishlist extends Model
{
    public static string $table = 'wishlists';
    public static array $fillable = ['user_id', 'product_id'];

    protected static function relations(): array
    {
        return ['product' => ['belongsTo', Product::class, 'product_id', 'id']];
    }
}
