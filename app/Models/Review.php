<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $fillable = [
        'product_id', 'consumer_id', 'order_id',
        'rating', 'comment', 'images', 'is_verified_purchase',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'is_verified_purchase' => 'boolean',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function consumer()
    {
        return $this->belongsTo(User::class, 'consumer_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
