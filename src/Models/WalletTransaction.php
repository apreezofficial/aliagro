<?php

namespace App\Models;

use App\Core\Model;

class WalletTransaction extends Model
{
    public static string $table = 'wallet_transactions';
    public static array $casts = ['amount' => 'float', 'balance_before' => 'float', 'balance_after' => 'float'];
    public static array $fillable = [
        'wallet_id', 'user_id', 'reference', 'amount', 'type', 'category', 'balance_before', 'balance_after',
        'description', 'gateway', 'gateway_reference', 'status', 'transactable_id', 'transactable_type',
    ];
}
