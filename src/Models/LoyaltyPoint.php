<?php

namespace App\Models;

use App\Core\Model;

class LoyaltyPoint extends Model
{
    public static string $table = 'loyalty_points';
    public static array $fillable = ['user_id', 'balance'];
}
