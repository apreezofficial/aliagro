<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = [
        'code', 'type', 'value', 'minimum_order',
        'maximum_discount', 'usage_limit', 'used_count',
        'is_active', 'starts_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'value' => 'float',
            'minimum_order' => 'float',
            'maximum_discount' => 'float',
        ];
    }

    public function isValid(): bool
    {
        if (!$this->is_active) return false;
        if ($this->usage_limit && $this->used_count >= $this->usage_limit) return false;
        if ($this->starts_at && now()->lt($this->starts_at)) return false;
        if ($this->expires_at && now()->gt($this->expires_at)) return false;
        return true;
    }

    public function calculateDiscount(float $orderTotal): float
    {
        if ($orderTotal < $this->minimum_order) return 0;

        $discount = $this->type === 'percentage'
            ? ($orderTotal * $this->value / 100)
            : $this->value;

        if ($this->maximum_discount) {
            $discount = min($discount, $this->maximum_discount);
        }

        return round($discount, 2);
    }
}
