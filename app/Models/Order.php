<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_number', 'consumer_id', 'subtotal', 'delivery_fee',
        'discount', 'total', 'status', 'payment_status',
        'payment_method', 'payment_reference', 'delivery_address',
        'delivery_state', 'delivery_lga', 'delivery_phone',
        'notes', 'paid_at', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'delivered_at' => 'datetime',
            'subtotal' => 'float',
            'delivery_fee' => 'float',
            'discount' => 'float',
            'total' => 'float',
        ];
    }

    public function consumer()
    {
        return $this->belongsTo(User::class, 'consumer_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public static function generateOrderNumber(): string
    {
        return 'ALG-' . strtoupper(uniqid());
    }
}
