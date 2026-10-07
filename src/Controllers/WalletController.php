<?php

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\OrderPlacedNotification;
use App\Services\LoyaltyService;

class WalletController
{
    public function index(Request $request): Response
    {
        $wallet = Wallet::forUser($request->user['id']);

        return json_response([
            'wallet' => [
                'balance'           => $wallet['balance'],
                'locked_balance'    => $wallet['locked_balance'],
                'available_balance' => Wallet::availableBalance($wallet),
                'currency'          => $wallet['currency'],
            ],
            'transactions' => WalletTransaction::where('wallet_id', $wallet['id'])->latest()->paginate(20),
        ]);
    }

    /** Pay for an order from the wallet balance. */
    public function payWithWallet(Request $request): Response
    {
        $v     = $request->validate(['order_id' => 'required|exists:orders,id']);
        $user  = $request->user;
        $order = Order::findOrFail($v['order_id']);

        if ($order['consumer_id'] !== $user['id']) {
            throw new HttpException(403, 'Unauthorized.');
        }
        if ($order['payment_status'] === 'paid') {
            throw new HttpException(422, 'Order already paid.');
        }

        $wallet = Wallet::where('user_id', $user['id'])->first();
        if (!$wallet || Wallet::availableBalance($wallet) < $order['total']) {
            throw new HttpException(422, 'Insufficient wallet balance.', [
                'required'          => $order['total'],
                'available_balance' => $wallet ? Wallet::availableBalance($wallet) : 0,
            ]);
        }

        $paidOrder = DB::transaction(function () use ($wallet, $order, $user) {
            // Lock the order so two simultaneous requests can't both pay it.
            $locked = DB::first('SELECT payment_status FROM orders WHERE id = ? FOR UPDATE', [$order['id']]);
            if ($locked['payment_status'] === 'paid') {
                throw new HttpException(422, 'Order already paid.');
            }

            Wallet::debit($wallet['id'], $order['total'], 'payment', "Payment for order {$order['order_number']}", [
                'transactable_id'   => $order['id'],
                'transactable_type' => 'App\\Models\\Order',
            ]);

            $reference = 'WLT-' . Str::uniqueId();
            $paid = Order::update($order['id'], [
                'payment_status'    => 'paid',
                'payment_method'    => 'wallet',
                'payment_reference' => $reference,
                'status'            => 'confirmed',
                'paid_at'           => now(),
            ]);

            Transaction::create([
                'user_id'     => $user['id'],
                'order_id'    => $order['id'],
                'reference'   => $reference,
                'amount'      => $order['total'],
                'type'        => 'payment',
                'status'      => 'success',
                'description' => "Wallet payment for order {$order['order_number']}",
            ]);

            (new LoyaltyService())->awardOrderPoints($user['id'], $order['total'], ['id' => $order['id'], 'type' => 'App\\Models\\Order']);

            return $paid;
        });

        (new OrderPlacedNotification($paidOrder))->send($user);

        return json_response(['message' => 'Order paid successfully from wallet.']);
    }

    /** Admin: any user's wallet. */
    public function adminView(Request $request, string $userId): Response
    {
        $wallet = Wallet::where('user_id', (int) $userId)->first() ?? throw new \App\Core\ModelNotFoundException();
        $wallet = Wallet::load($wallet, ['user:id,name,email']);

        return json_response([
            'wallet'       => $wallet,
            'transactions' => WalletTransaction::where('wallet_id', $wallet['id'])->latest()->paginate(20),
        ]);
    }
}
