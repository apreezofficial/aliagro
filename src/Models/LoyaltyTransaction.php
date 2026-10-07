<?php

namespace App\Models;

use App\Core\Model;

class LoyaltyTransaction extends Model
{
    public static string $table = 'loyalty_transactions';
    public static array $fillable = ['user_id', 'points', 'type', 'description', 'balance_after', 'source_id', 'source_type'];
}
