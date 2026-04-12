<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'user_id', 'order_id', 'reference', 'amount',
        'type', 'status', 'gateway', 'gateway_reference',
        'gateway_response', 'description',
    ];

    protected function casts(): array
    {
        return [
            'gateway_response' => 'array',
            'amount' => 'float',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
