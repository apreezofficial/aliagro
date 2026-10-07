<?php

namespace App\Models;

use App\Core\Model;

class Category extends Model
{
    public static string $table = 'categories';
    public static array $casts = ['is_active' => 'bool'];
    public static array $fillable = ['name', 'slug', 'description', 'icon', 'image', 'parent_id', 'is_active', 'sort_order'];

    protected static function relations(): array
    {
        return [
            'parent'   => ['belongsTo', Category::class, 'parent_id', 'id'],
            'children' => ['hasMany', Category::class, 'parent_id', 'id'],
            'products' => ['hasMany', Product::class, 'category_id', 'id'],
        ];
    }
}
