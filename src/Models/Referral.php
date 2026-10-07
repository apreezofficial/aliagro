<?php

namespace App\Models;

use App\Core\Model;

class Referral extends Model
{
    public static string $table = 'referrals';
    public static array $casts = ['rewarded_at' => 'datetime', 'bonus_amount' => 'float'];
    public static array $fillable = ['referrer_id', 'referred_id', 'status', 'bonus_amount', 'rewarded_at'];

    protected static function relations(): array
    {
        return [
            'referrer' => ['belongsTo', User::class, 'referrer_id', 'id'],
            'referred' => ['belongsTo', User::class, 'referred_id', 'id'],
        ];
    }
}
