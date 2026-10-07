<?php

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Services\LoyaltyService;

class LoyaltyController
{
    public function index(Request $request): Response
    {
        $userId  = $request->user['id'];
        $loyalty = LoyaltyPoint::firstOrCreate(['user_id' => $userId], ['balance' => 0]);

        return json_response([
            'points'      => $loyalty['balance'],
            'naira_value' => $loyalty['balance'], // 1 point = ₦1
            'history'     => LoyaltyTransaction::where('user_id', $userId)->latest()->paginate(20),
        ]);
    }

    /** Redeem points for a discount on an unpaid order. */
    public function redeem(Request $request): Response
    {
        $v = $request->validate([
            'points'   => 'required|integer|min:100',
            'order_id' => 'required|exists:orders,id',
        ]);

        $order = Order::findOrFail($v['order_id']);
        $user  = $request->user;

        if ($order['consumer_id'] !== $user['id']) {
            throw new HttpException(403, 'Unauthorized.');
        }
        if ($order['payment_status'] === 'paid') {
            throw new HttpException(422, 'Order already paid.');
        }

        $points = (int) $v['points'];

        $newTotal = DB::transaction(function () use ($user, $order, $points) {
            $discount = (new LoyaltyService())->redeemPoints($user['id'], $points, ['id' => $order['id'], 'type' => 'App\\Models\\Order']);
            $newTotal = max(0, $order['total'] - $discount);
            Order::update($order['id'], ['discount' => $order['discount'] + $discount, 'total' => $newTotal]);
            return [$discount, $newTotal];
        });
        [$discount, $newTotal] = $newTotal;

        return json_response([
            'message'   => "{$points} points redeemed for ₦{$discount} discount.",
            'discount'  => $discount,
            'new_total' => $newTotal,
        ]);
    }
}
