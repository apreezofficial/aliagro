<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'farmer_id', 'category_id', 'name', 'slug', 'description',
        'price', 'discount_price', 'unit', 'quantity_available',
        'minimum_order', 'images', 'thumbnail', 'status',
        'is_featured', 'is_organic', 'harvest_date', 'expiry_date',
        'location', 'rating', 'rating_count', 'total_sold', 'views',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'is_featured' => 'boolean',
            'is_organic' => 'boolean',
            'price' => 'float',
            'discount_price' => 'float',
            'rating' => 'float',
        ];
    }

    public function farmer()
    {
        return $this->belongsTo(User::class, 'farmer_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function wishlistedBy()
    {
        return $this->hasMany(Wishlist::class);
    }

    public function getEffectivePriceAttribute(): float
    {
        return $this->discount_price ?? $this->price;
    }

    public function isInStock(): bool
    {
        return $this->quantity_available > 0 && $this->status === 'active';
    }
}
