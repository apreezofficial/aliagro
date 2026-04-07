<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarmerProfile extends Model
{
    protected $fillable = [
        'user_id', 'farm_name', 'bio', 'farm_address',
        'state', 'lga', 'country', 'latitude', 'longitude',
        'farm_size', 'farm_images', 'bank_name',
        'bank_account_number', 'bank_account_name',
        'is_verified', 'total_sales', 'rating', 'rating_count',
    ];

    protected function casts(): array
    {
        return [
            'farm_images' => 'array',
            'is_verified' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'rating' => 'float',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
