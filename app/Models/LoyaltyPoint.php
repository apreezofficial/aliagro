<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyPoint extends Model
{
    protected $fillable = ['user_id', 'balance'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(LoyaltyTransaction::class, 'user_id', 'user_id');
    }

    // 100 points = ₦100 discount
    public function toNaira(): float
    {
        return $this->balance / 100;
    }
}
