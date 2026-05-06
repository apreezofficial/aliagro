<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * Consumer: Place an order from cart or direct.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'items'            => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'delivery_address' => 'required|string',
            'delivery_state'   => 'required|string',
            'delivery_lga'     => 'nullable|string',
            'delivery_phone'   => 'required|string',
            'notes'            => 'nullable|string',
            'coupon_code'      => 'nullable|string',
        ]);

        $user = $request->user();

        return DB::transaction(function () use ($request, $user) {
            $subtotal = 0;
            $orderItems = [];

            foreach ($request->items as $item) {
                $product = Product::findOrFail($item['product_id']);

                if (!$product->isInStock()) {
                    return response()->json([
                        'message' => "Product '{$product->name}' is out of stock.",
                    ], 422);
                }

                if ($item['quantity'] < $product->minimum_order) {
                    return response()->json([
                        'message' => "Minimum order for '{$product->name}' is {$product->minimum_order} {$product->unit}.",
                    ], 422);
                }

                if ($item['quantity'] > $product->quantity_available) {
                    return response()->json([
                        'message' => "Only {$product->quantity_available} {$product->unit} of '{$product->name}' available.",
                    ], 422);
                }

                $price     = $product->effective_price;
                $itemTotal = $price * $item['quantity'];
                $subtotal += $itemTotal;

                $orderItems[] = [
                    'product'      => $product,
                    'quantity'     => $item['quantity'],
                    'unit_price'   => $price,
                    'subtotal'     => $itemTotal,
                ];
            }

            // Coupon
            $discount = 0;
            if ($request->coupon_code) {
                $coupon = Coupon::where('code', $request->coupon_code)->first();
                if (!$coupon || !$coupon->isValid()) {
                    return response()->json(['message' => 'Invalid or expired coupon.'], 422);
                }
                $discount = $coupon->calculateDiscount($subtotal);
                $coupon->increment('used_count');
            }

            $deliveryFee = $this->calculateDeliveryFee($request->delivery_state);
            $total       = $subtotal - $discount + $deliveryFee;

            $order = Order::create([
                'order_number'     => Order::generateOrderNumber(),
                'consumer_id'      => $user->id,
                'subtotal'         => $subtotal,
                'delivery_fee'     => $deliveryFee,
                'discount'         => $discount,
                'total'            => $total,
                'delivery_address' => $request->delivery_address,
                'delivery_state'   => $request->delivery_state,
                'delivery_lga'     => $request->delivery_lga,
                'delivery_phone'   => $request->delivery_phone,
                'notes'            => $request->notes,
            ]);

            foreach ($orderItems as $item) {
                OrderItem::create([
                    'order_id'     => $order->id,
                    'product_id'   => $item['product']->id,
                    'farmer_id'    => $item['product']->farmer_id,
                    'product_name' => $item['product']->name,
                    'unit_price'   => $item['unit_price'],
                    'quantity'     => $item['quantity'],
                    'unit'         => $item['product']->unit,
                    'subtotal'     => $item['subtotal'],
                ]);

                // Deduct stock
                $item['product']->decrement('quantity_available', $item['quantity']);
            }

            return response()->json([
                'message' => 'Order placed successfully.',
                'order'   => $order->load('items.product'),
            ], 201);
        });
    }

    /**
     * Consumer: List own orders.
     */
    public function myOrders(Request $request): JsonResponse
    {
        $orders = Order::where('consumer_id', $request->user()->id)
            ->with('items.product:id,name,thumbnail')
            ->latest()
            ->paginate(15);

        return response()->json($orders);
    }

    /**
     * Consumer/Farmer: Get order details.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();

        $isBuyer  = $order->consumer_id === $user->id;
        $isFarmer = $order->items()->where('farmer_id', $user->id)->exists();

        if (!$isBuyer && !$isFarmer && !$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'order' => $order->load('items.product', 'items.farmer:id,name', 'consumer:id,name,email,phone'),
        ]);
    }

    /**
     * Consumer: Cancel an order.
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        if ($order->consumer_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if (!in_array($order->status, ['pending', 'confirmed'])) {
            return response()->json(['message' => 'Order cannot be cancelled at this stage.'], 422);
        }

        DB::transaction(function () use ($order) {
            $order->update(['status' => 'cancelled']);

            // Restore stock
            foreach ($order->items as $item) {
                $item->product?->increment('quantity_available', $item->quantity);
                $item->update(['status' => 'cancelled']);
            }
        });

        return response()->json(['message' => 'Order cancelled.']);
    }

    /**
     * Farmer: Update order item status.
     */
    public function updateItemStatus(Request $request, int $itemId): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:confirmed,shipped,delivered',
        ]);

        $item = \App\Models\OrderItem::findOrFail($itemId);

        if ($item->farmer_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $item->update(['status' => $request->status]);

        // If all items delivered, mark order delivered
        $order = $item->order;
        if ($order->items()->where('status', '!=', 'delivered')->doesntExist()) {
            $order->update(['status' => 'delivered', 'delivered_at' => now()]);
            // Notify consumer
            $order->consumer->notify(new \App\Notifications\OrderDeliveredNotification($order));
            // Award badges
            app(\App\Services\BadgeService::class)->evaluateFarmerBadges($item->farmer);
            app(\App\Services\BadgeService::class)->evaluateConsumerBadges($order->consumer);
            // Reward referral if first order
            $this->rewardReferralIfFirstOrder($order->consumer);
        }

        // Notify on shipped
        if ($request->status === 'shipped') {
            $order->consumer->notify(new \App\Notifications\OrderShippedNotification($order));
        }

        return response()->json(['message' => 'Item status updated.', 'item' => $item]);
    }

    /**
     * Farmer: List orders containing their products.
     */
    public function farmerOrders(Request $request): JsonResponse
    {
        $items = \App\Models\OrderItem::where('farmer_id', $request->user()->id)
            ->with('order.consumer:id,name,phone', 'product:id,name,thumbnail')
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(15);

        return response()->json($items);
    }

    private function calculateDeliveryFee(string $state): float
    {
        // Simple flat-rate by state zone — can be replaced with a delivery API
        $zones = [
            'Lagos'  => 1500,
            'Abuja'  => 2000,
            'Rivers' => 2500,
        ];

        return $zones[$state] ?? 3000;
    }

    private function rewardReferralIfFirstOrder($consumer): void
    {
        $orderCount = $consumer->orders()->where('status', 'delivered')->count();
        if ($orderCount !== 1) return;

        $referral = \App\Models\Referral::where('referred_id', $consumer->id)
            ->where('status', 'pending')
            ->first();

        if (!$referral) return;

        $bonus = 500;
        $referral->update([
            'status'       => 'rewarded',
            'bonus_amount' => $bonus,
            'rewarded_at'  => now(),
        ]);

        $referrer = $referral->referrer;
        $wallet   = $referrer->wallet ?? $referrer->wallet()->create(['balance' => 0]);
        $wallet->credit($bonus, 'referral_bonus', "Referral bonus for inviting {$consumer->name}");

        app(\App\Services\LoyaltyService::class)->awardReferralPoints($referrer, $referral);
        app(\App\Services\LoyaltyService::class)->awardReferralPoints($consumer, $referral);
    }
}
