<?php

namespace App\Services;

use App\Models\Badge;
use App\Models\User;

class BadgeService
{
    /**
     * Evaluate and award badges to a farmer after each order.
     */
    public function evaluateFarmerBadges(User $farmer): void
    {
        $profile = $farmer->farmerProfile;
        if (!$profile) return;

        $totalSales = $farmer->farmerOrders()
            ->whereHas('order', fn($q) => $q->where('status', 'delivered'))
            ->count();

        $avgRating = $profile->rating;

        // Top Seller: 50+ delivered orders
        if ($totalSales >= 50) {
            $this->awardBadge($farmer, 'top-seller');
        }

        // Fast Shipper: avg rating >= 4.5 with 20+ reviews
        if ($avgRating >= 4.5 && $profile->rating_count >= 20) {
            $this->awardBadge($farmer, 'fast-shipper');
        }

        // Organic Certified: KYC approved + is_organic products
        if ($farmer->isKycApproved() && $farmer->products()->where('is_organic', true)->exists()) {
            $this->awardBadge($farmer, 'organic-certified');
        }
    }

    /**
     * Evaluate consumer badges.
     */
    public function evaluateConsumerBadges(User $consumer): void
    {
        $orderCount = $consumer->orders()->where('status', 'delivered')->count();

        // Loyal Buyer: 10+ delivered orders
        if ($orderCount >= 10) {
            $this->awardBadge($consumer, 'loyal-buyer');
        }

        // Big Spender: total spend >= ₦100,000
        $totalSpend = $consumer->orders()->where('status', 'delivered')->sum('total');
        if ($totalSpend >= 100000) {
            $this->awardBadge($consumer, 'big-spender');
        }
    }

    private function awardBadge(User $user, string $slug): void
    {
        $badge = Badge::where('slug', $slug)->first();
        if (!$badge) return;

        // Only award once
        if (!$user->badges()->where('badge_id', $badge->id)->exists()) {
            $user->badges()->attach($badge->id, ['awarded_at' => now()]);
        }
    }
}
