<?php

namespace App\Models;

use App\Core\Model;

class Badge extends Model
{
    public static string $table = 'badges';
    public static array $fillable = ['name', 'slug', 'description', 'icon', 'type'];
}
