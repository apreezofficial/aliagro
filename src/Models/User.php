<?php

namespace App\Models;

use App\Core\DB;
use App\Core\Model;

class User extends Model
{
    public static string $table = 'users';
    public static array $casts = ['email_verified_at' => 'datetime'];
    public static array $hidden = ['password', 'remember_token', 'google_token'];
    public static array $fillable = [
        'name', 'email', 'phone', 'avatar', 'role', 'status', 'google_id', 'google_token',
        'password', 'email_verified_at', 'referral_code', 'gdpr_data_requested', 'gdpr_requested_at',
    ];

    protected static function relations(): array
    {
        return [
            'farmer_profile'     => ['hasOne', FarmerProfile::class, 'user_id', 'id'],
            'kyc_verification'   => ['hasOne', KycVerification::class, 'user_id', 'id'],
            'products'           => ['hasMany', Product::class, 'farmer_id', 'id'],
            'orders'             => ['hasMany', Order::class, 'consumer_id', 'id'],
            'reviews'            => ['hasMany', Review::class, 'consumer_id', 'id'],
            'delivery_addresses' => ['hasMany', DeliveryAddress::class, 'user_id', 'id'],
            'login_activities'   => ['hasMany', LoginActivity::class, 'user_id', 'id'],
            'wallet'             => ['hasOne', Wallet::class, 'user_id', 'id'],
            'loyalty_points'     => ['hasOne', LoyaltyPoint::class, 'user_id', 'id'],
            'badges'             => ['belongsToMany', Badge::class, 'user_badges', 'user_id', 'badge_id', ['awarded_at']],
        ];
    }

    /** Row *including* the password hash (hydrate() strips it). Only for authentication. */
    public static function findWithPassword(int|string $id): ?array
    {
        return DB::first('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function findByEmailWithPassword(string $email): ?array
    {
        return DB::first('SELECT * FROM users WHERE email = ?', [$email]);
    }

    public static function isFarmer(array $u): bool   { return $u['role'] === 'farmer'; }
    public static function isConsumer(array $u): bool { return $u['role'] === 'consumer'; }
    public static function isAdmin(array $u): bool    { return $u['role'] === 'admin'; }

    public static function hasVerifiedEmail(array $u): bool
    {
        return !empty($u['email_verified_at']);
    }

    public static function isKycApproved(int $userId): bool
    {
        return KycVerification::where('user_id', $userId)->where('status', 'approved')->exists();
    }
}
