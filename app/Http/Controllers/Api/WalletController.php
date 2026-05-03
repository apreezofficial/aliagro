<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * Get wallet balance and recent transactions.
     */
    public function index(Request $request): JsonResponse
    {
        $user   = $request->user();
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0, 'locked_balance' => 0]
        );

        $transactions = $wallet->transactions()
            ->latest()
            ->paginate(20);

        return response()->json([
            'wallet'       => [
                'balance'           => $wallet->balance,
                'locked_balance'    => $wallet->locked_balance,
                'available_balance' => $wallet->available_balance,
                'currency'          => $wallet->currency,
            ],
            'transactions' => $transactions,
        ]);
    }

    /**
     * Pay for an order using wallet balance.
     */
    public function payWithWallet(Request $request): JsonResponse
    {
        $request->validate(['order_id' => 'required|exists:orders,id']);

        $user  = $request->user();
        $order = \App\Models\Order::findOrFail($request->order_id);

        if ($order->consumer_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($order->payment_status === 'paid') {
            return response()->json(['message' => 'Order already paid.'], 422);
        }

        $wallet = $user->wallet;

        if (!$wallet || $wallet->available_balance < $order->total) {
            return response()->json([
                'message'           => 'Insufficient wallet balance.',
                'required'          => $order->total,
                'available_balance' => $wallet?->available_balance ?? 0,
            ], 422);
        }

        \DB::transaction(function () use ($wallet, $order, $user) {
            $wallet->debit($order->total, 'payment', "Payment for order {$order->order_number}", [
                'transactable_id'   => $order->id,
                'transactable_type' => \App\Models\Order::class,
            ]);

            $order->update([
                'payment_status'    => 'paid',
                'payment_method'    => 'wallet',
                'payment_reference' => 'WLT-' . strtoupper(uniqid()),
                'status'            => 'confirmed',
                'paid_at'           => now(),
            ]);

            \App\Models\Transaction::create([
                'user_id'     => $user->id,
                'order_id'    => $order->id,
                'reference'   => 'WLT-' . strtoupper(uniqid()),
                'amount'      => $order->total,
                'type'        => 'payment',
                'status'      => 'success',
                'description' => "Wallet payment for order {$order->order_number}",
            ]);

            app(\App\Services\LoyaltyService::class)->awardOrderPoints($user, $order->total, $order);
            $user->notify(new \App\Notifications\OrderPlacedNotification($order));
        });

        return response()->json(['message' => 'Order paid successfully from wallet.']);
    }

    /**
     * Admin: view any user's wallet.
     */
    public function adminView(int $userId): JsonResponse
    {
        $wallet = Wallet::where('user_id', $userId)->with('user:id,name,email')->firstOrFail();

        return response()->json([
            'wallet'       => $wallet,
            'transactions' => $wallet->transactions()->latest()->paginate(20),
        ]);
    }
}
