<?php

namespace App\Models;

use App\Core\Model;

class Cart extends Model
{
    public static string $table = 'carts';
    public static array $fillable = ['user_id'];

    protected static function relations(): array
    {
        return ['items' => ['hasMany', CartItem::class, 'cart_id', 'id']];
    }
}
