<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'phone', 'avatar', 'role',
        'status', 'google_id', 'google_token', 'password',
        'email_verified_at', 'referral_code',
        'gdpr_data_requested', 'gdpr_requested_at',
    ];

    protected $hidden = [
        'password', 'remember_token', 'google_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Relationships
    public function farmerProfile()
    {
        return $this->hasOne(FarmerProfile::class);
    }

    public function kycVerification()
    {
        return $this->hasOne(KycVerification::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class, 'farmer_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'consumer_id');
    }

    public function farmerOrders()
    {
        return $this->hasManyThrough(OrderItem::class, Product::class, 'farmer_id', 'product_id');
    }

    public function cart()
    {
        return $this->hasOne(Cart::class);
    }

    public function wishlist()
    {
        return $this->hasMany(Wishlist::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class, 'consumer_id');
    }

    public function deliveryAddresses()
    {
        return $this->hasMany(DeliveryAddress::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function loyaltyPoints()
    {
        return $this->hasOne(LoyaltyPoint::class);
    }

    public function referrals()
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    public function referredBy()
    {
        return $this->hasOne(Referral::class, 'referred_id');
    }

    public function badges()
    {
        return $this->belongsToMany(Badge::class, 'user_badges')
            ->withPivot('awarded_at')
            ->withTimestamps();
    }

    public function loginActivities()
    {
        return $this->hasMany(LoginActivity::class);
    }

    public function following()
    {
        return $this->belongsToMany(User::class, 'farmer_follows', 'follower_id', 'farmer_id')
            ->withTimestamps();
    }

    public function followers()
    {
        return $this->belongsToMany(User::class, 'farmer_follows', 'farmer_id', 'follower_id')
            ->withTimestamps();
    }

    public function recentlyViewed()
    {
        return $this->hasMany(ProductView::class)->orderByDesc('last_viewed_at');
    }

    // Helpers
    public function isFarmer(): bool
    {
        return $this->role === 'farmer';
    }

    public function isConsumer(): bool
    {
        return $this->role === 'consumer';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isKycApproved(): bool
    {
        return $this->kycVerification?->status === 'approved';
    }
}
