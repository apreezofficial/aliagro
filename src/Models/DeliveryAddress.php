<?php

namespace App\Models;

use App\Core\Model;

class DeliveryAddress extends Model
{
    public static string $table = 'delivery_addresses';
    public static array $casts = ['is_default' => 'bool'];
    public static array $fillable = ['user_id', 'label', 'recipient_name', 'phone', 'address', 'state', 'lga', 'country', 'is_default'];
}
