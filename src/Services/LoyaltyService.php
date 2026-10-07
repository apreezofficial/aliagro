<?php

namespace App\Services;

use App\Core\DB;
use App\Core\HttpException;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyTransaction;

class LoyaltyService
{
    // 1 point per ₦1000 spent
    public const POINTS_PER_NAIRA = 1;
    public const NAIRA_DIVISOR    = 1000;

    /** Award points for a paid order. @param array{id:int,type:string}|null $source */
    public function awardOrderPoints(int $userId, float $orderTotal, ?array $source = null): int
    {
        $points = (int) floor($orderTotal / self::NAIRA_DIVISOR) * self::POINTS_PER_NAIRA;
        if ($points <= 0) {
            return 0;
        }

        return $this->addPoints($userId, $points, 'earn', 'Earned for order worth ₦' . number_format($orderTotal, 2), $source);
    }

    public function awardReferralPoints(int $userId, ?array $source = null): int
    {
        return $this->addPoints($userId, 50, 'bonus', 'Referral bonus', $source);
    }

    /** Deduct points; returns the naira value (1 point = ₦1). */
    public function redeemPoints(int $userId, int $points, ?array $source = null): float
    {
        return DB::transaction(function () use ($userId, $points, $source) {
            $loyalty = DB::first('SELECT * FROM loyalty_points WHERE user_id = ? FOR UPDATE', [$userId]);

            if (!$loyalty || $loyalty['balance'] < $points) {
                throw new HttpException(422, 'Insufficient loyalty points.');
            }

            $newBalance = $loyalty['balance'] - $points;
            LoyaltyPoint::update($loyalty['id'], ['balance' => $newBalance]);

            LoyaltyTransaction::create([
                'user_id'       => $userId,
                'points'        => -$points,
                'type'          => 'redeem',
                'description'   => "Redeemed {$points} points for ₦{$points} discount",
                'balance_after' => $newBalance,
                'source_id'     => $source['id'] ?? null,
                'source_type'   => $source['type'] ?? null,
            ]);

            return (float) $points;
        });
    }

    private function addPoints(int $userId, int $points, string $type, string $description, ?array $source): int
    {
        DB::transaction(function () use ($userId, $points, $type, $description, $source) {
            LoyaltyPoint::firstOrCreate(['user_id' => $userId], ['balance' => 0]);
            $loyalty = DB::first('SELECT * FROM loyalty_points WHERE user_id = ? ORDER BY id LIMIT 1 FOR UPDATE', [$userId]);

            $newBalance = $loyalty['balance'] + $points;
            LoyaltyPoint::update($loyalty['id'], ['balance' => $newBalance]);

            LoyaltyTransaction::create([
                'user_id'       => $userId,
                'points'        => $points,
                'type'          => $type,
                'description'   => $description,
                'balance_after' => $newBalance,
                'source_id'     => $source['id'] ?? null,
                'source_type'   => $source['type'] ?? null,
            ]);
        });

        return $points;
    }
}
