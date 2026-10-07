<?php

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\ModelNotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Referral;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\OrderDeliveredNotification;
use App\Notifications\OrderShippedNotification;
use App\Services\BadgeService;
use App\Services\LoyaltyService;

class OrderController
{
    /** Consumer: place an order. */
    public function store(Request $request): Response
    {
        $v = $request->validate([
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity'   => 'required|integer|min:1',
            'delivery_address'   => 'required|string',
            'delivery_state'     => 'required|string',
            'delivery_lga'       => 'nullable|string',
            'delivery_phone'     => 'required|string',
            'notes'              => 'nullable|string',
            'coupon_code'        => 'nullable|string',
        ]);
        $user = $request->user;

        // Merge repeated lines for the same product so stock checks see the real total.
        $wanted = [];
        foreach ($v['items'] as $line) {
            $pid = (int) $line['product_id'];
            $wanted[$pid] = ($wanted[$pid] ?? 0) + (int) $line['quantity'];
        }

        $order = DB::transaction(function () use ($wanted, $v, $user) {
            $subtotal = 0.0;
            $lines    = [];

            foreach ($wanted as $pid => $qty) {
                $row = DB::first('SELECT * FROM products WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$pid]);
                if (!$row) {
                    throw new ModelNotFoundException();
                }
                $product = Product::hydrate($row);

                if (!Product::isInStock($product)) {
                    throw new HttpException(422, "Product '{$product['name']}' is out of stock.");
                }
                if ($qty < $product['minimum_order']) {
                    throw new HttpException(422, "Minimum order for '{$product['name']}' is {$product['minimum_order']} {$product['unit']}.");
                }
                if ($qty > $product['quantity_available']) {
                    throw new HttpException(422, "Only {$product['quantity_available']} {$product['unit']} of '{$product['name']}' available.");
                }

                $price     = Product::effectivePrice($product);
                $subtotal += $price * $qty;
                $lines[]   = ['product' => $product, 'quantity' => $qty, 'unit_price' => $price, 'subtotal' => $price * $qty];
            }

            $discount = 0.0;
            if (!empty($v['coupon_code'])) {
                $coupon = Coupon::where('code', $v['coupon_code'])->first();
                if (!$coupon || !Coupon::isValid($coupon)) {
                    throw new HttpException(422, 'Invalid or expired coupon.');
                }
                $discount = min(Coupon::calculateDiscount($coupon, $subtotal), $subtotal);

                // Atomic: the usage limit can't be exceeded by concurrent orders.
                $claimed = DB::statement(
                    'UPDATE coupons SET used_count = used_count + 1 WHERE id = ? AND (usage_limit IS NULL OR used_count < usage_limit)',
                    [$coupon['id']]
                );
                if (!$claimed) {
                    throw new HttpException(422, 'Invalid or expired coupon.');
                }
            }

            $deliveryFee = self::deliveryFee($v['delivery_state']);

            $order = Order::create([
                'order_number'     => Order::generateOrderNumber(),
                'consumer_id'      => $user['id'],
                'subtotal'         => $subtotal,
                'delivery_fee'     => $deliveryFee,
                'discount'         => $discount,
                'total'            => $subtotal - $discount + $deliveryFee,
                'delivery_address' => $v['delivery_address'],
                'delivery_state'   => $v['delivery_state'],
                'delivery_lga'     => $v['delivery_lga'] ?? null,
                'delivery_phone'   => $v['delivery_phone'],
                'notes'            => $v['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                OrderItem::create([
                    'order_id'     => $order['id'],
                    'product_id'   => $line['product']['id'],
                    'farmer_id'    => $line['product']['farmer_id'],
                    'product_name' => $line['product']['name'],
                    'unit_price'   => $line['unit_price'],
                    'quantity'     => $line['quantity'],
                    'unit'         => $line['product']['unit'],
                    'subtotal'     => $line['subtotal'],
                ]);
                Product::decrement($line['product']['id'], 'quantity_available', $line['quantity']);
            }

            return $order;
        });

        return json_response(['message' => 'Order placed successfully.', 'order' => Order::load($order, ['items.product'])], 201);
    }

    public function myOrders(Request $request): Response
    {
        return json_response(
            Order::where('consumer_id', $request->user['id'])->with('items.product:id,name,thumbnail')->latest()->paginate(15)
        );
    }

    public function show(Request $request, string $orderId): Response
    {
        $order = Order::findOrFail($orderId);
        $user  = $request->user;

        $isBuyer  = $order['consumer_id'] === $user['id'];
        $isFarmer = OrderItem::where('order_id', $order['id'])->where('farmer_id', $user['id'])->exists();

        if (!$isBuyer && !$isFarmer && !User::isAdmin($user)) {
            throw new HttpException(403, 'Unauthorized.');
        }

        return json_response([
            'order' => Order::load($order, ['items.product', 'items.farmer:id,name', 'consumer:id,name,email,phone']),
        ]);
    }

    public function cancel(Request $request, string $orderId): Response
    {
        $order = Order::findOrFail($orderId);

        if ($order['consumer_id'] !== $request->user['id']) {
            throw new HttpException(403, 'Unauthorized.');
        }

        DB::transaction(function () use ($order) {
            $locked = DB::first('SELECT status FROM orders WHERE id = ? FOR UPDATE', [$order['id']]);
            if (!in_array($locked['status'], ['pending', 'confirmed'], true)) {
                throw new HttpException(422, 'Order cannot be cancelled at this stage.');
            }

            Order::update($order['id'], ['status' => 'cancelled']);

            foreach (OrderItem::where('order_id', $order['id'])->get() as $item) {
                DB::statement(
                    'UPDATE products SET quantity_available = quantity_available + ? WHERE id = ? AND deleted_at IS NULL',
                    [$item['quantity'], $item['product_id']]
                );
                OrderItem::update($item['id'], ['status' => 'cancelled']);
            }
        });

        return json_response(['message' => 'Order cancelled.']);
    }

    /** Farmer: update the status of one of their order lines. */
    public function updateItemStatus(Request $request, string $itemId): Response
    {
        $v    = $request->validate(['status' => 'required|in:confirmed,shipped,delivered']);
        $item = OrderItem::findOrFail($itemId);

        if ($item['farmer_id'] !== $request->user['id']) {
            throw new HttpException(403, 'Unauthorized.');
        }

        $completed = DB::transaction(function () use ($item, $v) {
            OrderItem::update($item['id'], ['status' => $v['status']]);

            $order = DB::first('SELECT id, status FROM orders WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$item['order_id']]);
            if (!$order) {
                throw new ModelNotFoundException();
            }

            $allDelivered = OrderItem::where('order_id', $order['id'])->where('status', '!=', 'delivered')->doesntExist();
            if ($allDelivered && $order['status'] !== 'delivered') {
                Order::update($order['id'], ['status' => 'delivered', 'delivered_at' => now()]);
                return true;
            }
            return false;
        });

        $order    = Order::findOrFail($item['order_id']);
        $consumer = User::findOrFail($order['consumer_id']);

        if ($completed) {
            (new OrderDeliveredNotification($order))->send($consumer);
            $badges = new BadgeService();
            $badges->evaluateFarmerBadges($item['farmer_id']);
            $badges->evaluateConsumerBadges($consumer['id']);
            $this->rewardReferralIfFirstOrder($consumer);
        }

        if ($v['status'] === 'shipped') {
            (new OrderShippedNotification($order))->send($consumer);
        }

        return json_response(['message' => 'Item status updated.', 'item' => OrderItem::findOrFail($item['id'])]);
    }

    /** Farmer: order lines containing their products. */
    public function farmerOrders(Request $request): Response
    {
        $q = OrderItem::where('farmer_id', $request->user['id'])
            ->with(['order.consumer:id,name,phone', 'product:id,name,thumbnail']);
        if ($request->input('status')) {
            $q->where('status', $request->input('status'));
        }

        return json_response($q->latest()->paginate(15));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** Flat rate per state zone; replace with a delivery API when available. */
    private static function deliveryFee(string $state): float
    {
        return [
            'Lagos'  => 1500.0,
            'Abuja'  => 2000.0,
            'Rivers' => 2500.0,
        ][$state] ?? 3000.0;
    }

    private function rewardReferralIfFirstOrder(array $consumer): void
    {
        if (Order::where('consumer_id', $consumer['id'])->where('status', 'delivered')->count() !== 1) {
            return;
        }

        $referral = Referral::where('referred_id', $consumer['id'])->where('status', 'pending')->first();
        if (!$referral) {
            return;
        }

        $bonus = 500;
        DB::transaction(function () use ($referral, $consumer, $bonus) {
            // Flip pending -> rewarded atomically so the bonus is paid exactly once.
            $claimed = DB::statement(
                "UPDATE referrals SET status = 'rewarded', bonus_amount = ?, rewarded_at = ?, updated_at = ? WHERE id = ? AND status = 'pending'",
                [$bonus, now(), now(), $referral['id']]
            );
            if (!$claimed) {
                return;
            }

            $wallet = Wallet::forUser($referral['referrer_id']);
            Wallet::credit($wallet['id'], $bonus, 'referral_bonus', "Referral bonus for inviting {$consumer['name']}");

            $loyalty = new LoyaltyService();
            $source  = ['id' => $referral['id'], 'type' => 'App\\Models\\Referral'];
            $loyalty->awardReferralPoints($referral['referrer_id'], $source);
            $loyalty->awardReferralPoints($consumer['id'], $source);
        });
    }
}
