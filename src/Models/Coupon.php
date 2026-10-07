<?php

namespace App\Models;

use App\Core\Model;
use App\Core\Time;

class Coupon extends Model
{
    public static string $table = 'coupons';
    public static array $casts = [
        'is_active' => 'bool', 'starts_at' => 'datetime', 'expires_at' => 'datetime',
        'value' => 'float', 'minimum_order' => 'float', 'maximum_discount' => 'float',
    ];
    public static array $fillable = [
        'code', 'type', 'value', 'minimum_order', 'maximum_discount', 'usage_limit',
        'used_count', 'is_active', 'starts_at', 'expires_at',
    ];

    public static function isValid(array $c): bool
    {
        if (!$c['is_active']) return false;
        if ($c['usage_limit'] && $c['used_count'] >= $c['usage_limit']) return false;
        if ($c['starts_at'] && time() < Time::ts($c['starts_at'])) return false;
        if ($c['expires_at'] && time() > Time::ts($c['expires_at'])) return false;
        return true;
    }

    public static function calculateDiscount(array $c, float $orderTotal): float
    {
        if ($orderTotal < $c['minimum_order']) return 0.0;

        $discount = $c['type'] === 'percentage'
            ? ($orderTotal * $c['value'] / 100)
            : $c['value'];

        if ($c['maximum_discount']) {
            $discount = min($discount, $c['maximum_discount']);
        }

        return round($discount, 2);
    }
}
