<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyTransaction;
use App\Services\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    public function __construct(private LoyaltyService $loyalty) {}

    /**
     * Get loyalty points balance and history.
     */
    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $loyalty = LoyaltyPoint::firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0]
        );

        $history = LoyaltyTransaction::where('user_id', $user->id)
            ->latest()
            ->paginate(20);

        return response()->json([
            'points'      => $loyalty->balance,
            'naira_value' => $loyalty->balance, // 1 point = ₦1
            'history'     => $history,
        ]);
    }

    /**
     * Redeem points for a discount on an order.
     */
    public function redeem(Request $request): JsonResponse
    {
        $request->validate([
            'points'   => 'required|integer|min:100',
            'order_id' => 'required|exists:orders,id',
        ]);

        $user  = $request->user();
        $order = \App\Models\Order::findOrFail($request->order_id);

        if ($order->consumer_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($order->payment_status === 'paid') {
            return response()->json(['message' => 'Order already paid.'], 422);
        }

        try {
            $discount = $this->loyalty->redeemPoints($user, $request->points, $order);

            // Apply discount to order
            $newTotal = max(0, $order->total - $discount);
            $order->update([
                'discount' => $order->discount + $discount,
                'total'    => $newTotal,
            ]);

            return response()->json([
                'message'      => "{$request->points} points redeemed for ₦{$discount} discount.",
                'discount'     => $discount,
                'new_total'    => $newTotal,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
