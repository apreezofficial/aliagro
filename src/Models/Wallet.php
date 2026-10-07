<?php

namespace App\Models;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Model;
use App\Core\Str;

class Wallet extends Model
{
    public static string $table = 'wallets';
    public static array $casts = ['balance' => 'float', 'locked_balance' => 'float'];
    public static array $fillable = ['user_id', 'balance', 'locked_balance', 'currency'];

    protected static function relations(): array
    {
        return [
            'user'         => ['belongsTo', User::class, 'user_id', 'id'],
            'transactions' => ['hasMany', WalletTransaction::class, 'wallet_id', 'id'],
        ];
    }

    public static function availableBalance(array $w): float
    {
        return $w['balance'] - $w['locked_balance'];
    }

    public static function forUser(int $userId): array
    {
        return static::firstOrCreate(['user_id' => $userId], ['balance' => 0, 'locked_balance' => 0]);
    }

    /** Credit the wallet and record a transaction (row-locked, so concurrent calls can't lose updates). */
    public static function credit(int $walletId, float $amount, string $category, string $description, array $extra = []): array
    {
        return DB::transaction(function () use ($walletId, $amount, $category, $description, $extra) {
            $wallet = DB::first('SELECT * FROM wallets WHERE id = ? FOR UPDATE', [$walletId]);
            $before = (float) $wallet['balance'];
            $after  = $before + $amount;
            DB::update('wallets', ['balance' => $after, 'updated_at' => now()], 'id = ?', [$walletId]);

            return static::record($wallet, 'credit', $amount, $category, $description, $before, $after, $extra);
        });
    }

    public static function debit(int $walletId, float $amount, string $category, string $description, array $extra = []): array
    {
        return DB::transaction(function () use ($walletId, $amount, $category, $description, $extra) {
            $wallet = DB::first('SELECT * FROM wallets WHERE id = ? FOR UPDATE', [$walletId]);
            $before = (float) $wallet['balance'];
            if ($before - (float) $wallet['locked_balance'] < $amount) {
                throw new HttpException(422, 'Insufficient wallet balance.');
            }
            $after = $before - $amount;
            DB::update('wallets', ['balance' => $after, 'updated_at' => now()], 'id = ?', [$walletId]);

            return static::record($wallet, 'debit', $amount, $category, $description, $before, $after, $extra);
        });
    }

    private static function record(array $wallet, string $type, float $amount, string $category, string $description, float $before, float $after, array $extra): array
    {
        return WalletTransaction::create(array_merge([
            'wallet_id'      => $wallet['id'],
            'user_id'        => $wallet['user_id'],
            'reference'      => 'WLT-' . Str::uniqueId() . strtoupper(bin2hex(random_bytes(2))),
            'amount'         => $amount,
            'type'           => $type,
            'category'       => $category,
            'balance_before' => $before,
            'balance_after'  => $after,
            'description'    => $description,
            'status'         => 'success',
        ], $extra));
    }
}
