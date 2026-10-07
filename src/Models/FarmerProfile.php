<?php

namespace App\Models;

use App\Core\Model;

class FarmerProfile extends Model
{
    public static string $table = 'farmer_profiles';
    public static array $casts = [
        'farm_images' => 'array', 'is_verified' => 'bool',
        'latitude' => 'float', 'longitude' => 'float', 'rating' => 'float',
    ];
    public static array $fillable = [
        'user_id', 'farm_name', 'bio', 'farm_address', 'state', 'lga', 'country', 'latitude', 'longitude',
        'farm_size', 'farm_images', 'bank_name', 'bank_account_number', 'bank_account_name',
        'is_verified', 'total_sales', 'rating', 'rating_count',
    ];
}
