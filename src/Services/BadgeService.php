<?php

namespace App\Services;

use App\Core\DB;
use App\Models\Badge;
use App\Models\FarmerProfile;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;

class BadgeService
{
    /** Evaluate and award badges to a farmer after an order completes. */
    public function evaluateFarmerBadges(int $farmerId): void
    {
        $profile = FarmerProfile::where('user_id', $farmerId)->first();
        if (!$profile) {
            return;
        }

        $totalSales = (int) DB::value(
            'SELECT COUNT(*) FROM order_items oi
             JOIN products p ON p.id = oi.product_id AND p.deleted_at IS NULL
             JOIN orders o ON o.id = oi.order_id AND o.deleted_at IS NULL
             WHERE p.farmer_id = ? AND o.status = ?',
            [$farmerId, 'delivered']
        );

        // Top Seller: 50+ delivered order lines
        if ($totalSales >= 50) {
            $this->awardBadge($farmerId, 'top-seller');
        }

        // Fast Shipper: rating >= 4.5 with 20+ reviews
        if ($profile['rating'] >= 4.5 && $profile['rating_count'] >= 20) {
            $this->awardBadge($farmerId, 'fast-shipper');
        }

        // Organic Certified: KYC approved + at least one organic product
        if (User::isKycApproved($farmerId) && Product::where('farmer_id', $farmerId)->where('is_organic', 1)->exists()) {
            $this->awardBadge($farmerId, 'organic-certified');
        }
    }

    public function evaluateConsumerBadges(int $consumerId): void
    {
        $delivered = Order::where('consumer_id', $consumerId)->where('status', 'delivered');

        // Loyal Buyer: 10+ delivered orders
        if ((clone $delivered)->count() >= 10) {
            $this->awardBadge($consumerId, 'loyal-buyer');
        }

        // Big Spender: total spend >= ₦100,000
        if ((float) (clone $delivered)->sum('total') >= 100000) {
            $this->awardBadge($consumerId, 'big-spender');
        }
    }

    public function awardBadge(int $userId, string $slug): bool
    {
        $badge = Badge::where('slug', $slug)->first();
        if (!$badge) {
            return false;
        }

        $ts = now();
        // user_badges has a unique (user_id, badge_id): INSERT IGNORE makes this race-free.
        return DB::statement(
            'INSERT IGNORE INTO user_badges (user_id, badge_id, awarded_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$userId, $badge['id'], $ts, $ts, $ts]
        ) > 0;
    }
}
