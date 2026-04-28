<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Referral extends Model
{
    protected $fillable = [
        'referrer_id', 'referred_id', 'status', 'bonus_amount', 'rewarded_at',
    ];

    protected function casts(): array
    {
        return [
            'rewarded_at'  => 'datetime',
            'bonus_amount' => 'float',
        ];
    }

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referred()
    {
        return $this->belongsTo(User::class, 'referred_id');
    }
}
