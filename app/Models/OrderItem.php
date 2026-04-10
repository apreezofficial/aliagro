<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'farmer_id', 'product_name',
        'unit_price', 'quantity', 'unit', 'subtotal', 'status',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'float',
            'subtotal' => 'float',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function farmer()
    {
        return $this->belongsTo(User::class, 'farmer_id');
    }
}
