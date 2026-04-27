<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Wallet extends Model
{
    protected $fillable = ['user_id', 'balance', 'locked_balance', 'currency'];

    protected function casts(): array
    {
        return [
            'balance'        => 'float',
            'locked_balance' => 'float',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function getAvailableBalanceAttribute(): float
    {
        return $this->balance - $this->locked_balance;
    }

    /**
     * Credit the wallet and record a transaction.
     */
    public function credit(float $amount, string $category, string $description, array $extra = []): WalletTransaction
    {
        return DB::transaction(function () use ($amount, $category, $description, $extra) {
            $before = $this->balance;
            $this->increment('balance', $amount);
            $this->refresh();

            return WalletTransaction::create(array_merge([
                'wallet_id'       => $this->id,
                'user_id'         => $this->user_id,
                'reference'       => 'WLT-' . strtoupper(uniqid()),
                'amount'          => $amount,
                'type'            => 'credit',
                'category'        => $category,
                'balance_before'  => $before,
                'balance_after'   => $this->balance,
                'description'     => $description,
                'status'          => 'success',
            ], $extra));
        });
    }

    /**
     * Debit the wallet and record a transaction.
     */
    public function debit(float $amount, string $category, string $description, array $extra = []): WalletTransaction
    {
        if ($this->available_balance < $amount) {
            throw new \Exception('Insufficient wallet balance.');
        }

        return DB::transaction(function () use ($amount, $category, $description, $extra) {
            $before = $this->balance;
            $this->decrement('balance', $amount);
            $this->refresh();

            return WalletTransaction::create(array_merge([
                'wallet_id'       => $this->id,
                'user_id'         => $this->user_id,
                'reference'       => 'WLT-' . strtoupper(uniqid()),
                'amount'          => $amount,
                'type'            => 'debit',
                'category'        => $category,
                'balance_before'  => $before,
                'balance_after'   => $this->balance,
                'description'     => $description,
                'status'          => 'success',
            ], $extra));
        });
    }
}
