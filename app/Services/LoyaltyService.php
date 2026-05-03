<?php

namespace App\Services;

use App\Models\LoyaltyPoint;
use App\Models\LoyaltyTransaction;
use App\Models\User;

class LoyaltyService
{
    // Points earned per ₦1000 spent
    const POINTS_PER_NAIRA = 1; // 1 point per ₦1000
    const NAIRA_DIVISOR    = 1000;

    /**
     * Award points for a completed order.
     */
    public function awardOrderPoints(User $user, float $orderTotal, $source = null): int
    {
        $points = (int) floor($orderTotal / self::NAIRA_DIVISOR) * self::POINTS_PER_NAIRA;

        if ($points <= 0) return 0;

        return $this->addPoints($user, $points, 'earn', "Earned for order worth ₦" . number_format($orderTotal, 2), $source);
    }

    /**
     * Award referral bonus points.
     */
    public function awardReferralPoints(User $user, $source = null): int
    {
        return $this->addPoints($user, 50, 'bonus', 'Referral bonus', $source);
    }

    /**
     * Redeem points (deduct).
     */
    public function redeemPoints(User $user, int $points, $source = null): float
    {
        $loyalty = $user->loyaltyPoints;

        if (!$loyalty || $loyalty->balance < $points) {
            throw new \Exception('Insufficient loyalty points.');
        }

        $nairaValue = $points; // 1 point = ₦1

        $newBalance = $loyalty->balance - $points;
        $loyalty->update(['balance' => $newBalance]);

        LoyaltyTransaction::create([
            'user_id'       => $user->id,
            'points'        => -$points,
            'type'          => 'redeem',
            'description'   => "Redeemed {$points} points for ₦{$nairaValue} discount",
            'balance_after' => $newBalance,
            'source_id'     => $source?->id,
            'source_type'   => $source ? get_class($source) : null,
        ]);

        return $nairaValue;
    }

    private function addPoints(User $user, int $points, string $type, string $description, $source = null): int
    {
        $loyalty = LoyaltyPoint::firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0]
        );

        $newBalance = $loyalty->balance + $points;
        $loyalty->update(['balance' => $newBalance]);

        LoyaltyTransaction::create([
            'user_id'       => $user->id,
            'points'        => $points,
            'type'          => $type,
            'description'   => $description,
            'balance_after' => $newBalance,
            'source_id'     => $source?->id,
            'source_type'   => $source ? get_class($source) : null,
        ]);

        return $points;
    }
}
