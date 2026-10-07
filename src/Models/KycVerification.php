<?php

namespace App\Models;

use App\Core\Model;

class KycVerification extends Model
{
    public static string $table = 'kyc_verifications';
    public static array $casts = ['reviewed_at' => 'datetime'];
    public static array $fillable = [
        'user_id', 'status', 'id_type', 'id_number', 'id_front_image', 'id_back_image', 'selfie_image',
        'address', 'state', 'country', 'rejection_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected static function relations(): array
    {
        return [
            'user'     => ['belongsTo', User::class, 'user_id', 'id'],
            'reviewer' => ['belongsTo', User::class, 'reviewed_by', 'id'],
        ];
    }
}
