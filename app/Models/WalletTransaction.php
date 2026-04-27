<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    protected $fillable = [
        'wallet_id', 'user_id', 'reference', 'amount', 'type',
        'category', 'balance_before', 'balance_after', 'description',
        'gateway', 'gateway_reference', 'status',
        'transactable_id', 'transactable_type',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'float',
            'balance_before' => 'float',
            'balance_after'  => 'float',
        ];
    }

    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactable()
    {
        return $this->morphTo();
    }
}
