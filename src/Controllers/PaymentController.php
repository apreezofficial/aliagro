<?php

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\OrderPlacedNotification;
use App\Services\FlutterwaveService;
use App\Services\LoyaltyService;
use App\Services\PaystackService;

class PaymentController
{
    private PaystackService $paystack;
    private FlutterwaveService $flutterwave;
    private LoyaltyService $loyalty;

    public function __construct()
    {
        $this->paystack    = new PaystackService();
        $this->flutterwave = new FlutterwaveService();
        $this->loyalty     = new LoyaltyService();
    }

    /** Start a gateway payment for an order. */
    public function initializeOrderPayment(Request $request): Response
    {
        $v = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'gateway'  => 'required|in:paystack,flutterwave',
        ]);

        $order = Order::findOrFail($v['order_id']);
        $user  = $request->user;

        if ($order['consumer_id'] !== $user['id']) {
            throw new HttpException(403, 'Unauthorized.');
        }
        if ($order['payment_status'] === 'paid') {
            throw new HttpException(422, 'Order already paid.');
        }

        $reference = 'ALG-PAY-' . strtoupper(Str::random(12));

        try {
            [$data, $authUrl] = $this->initialize($v['gateway'], $user, $order['total'], $reference, ['order_id' => $order['id'], 'user_id' => $user['id']]);
        } catch (\RuntimeException $e) {
            throw new HttpException(500, $e->getMessage());
        }

        PaymentIntent::create([
            'user_id'           => $user['id'],
            'order_id'          => $order['id'],
            'reference'         => $reference,
            'amount'            => $order['total'],
            'gateway'           => $v['gateway'],
            'purpose'           => 'order_payment',
            'authorization_url' => $authUrl,
            'gateway_response'  => $data,
        ]);

        return json_response([
            'message'           => 'Payment initialized.',
            'reference'         => $reference,
            'authorization_url' => $authUrl,
            'gateway'           => $v['gateway'],
        ]);
    }

    /** Start a wallet top-up. */
    public function initializeTopup(Request $request): Response
    {
        $v = $request->validate([
            'amount'  => 'required|numeric|min:100',
            'gateway' => 'required|in:paystack,flutterwave',
        ]);
        $user      = $request->user;
        $reference = 'ALG-TOP-' . strtoupper(Str::random(12));

        try {
            [$data, $authUrl] = $this->initialize($v['gateway'], $user, (float) $v['amount'], $reference, []);
        } catch (\RuntimeException $e) {
            throw new HttpException(500, $e->getMessage());
        }

        PaymentIntent::create([
            'user_id'           => $user['id'],
            'reference'         => $reference,
            'amount'            => $v['amount'],
            'gateway'           => $v['gateway'],
            'purpose'           => 'wallet_topup',
            'authorization_url' => $authUrl,
            'gateway_response'  => $data,
        ]);

        return json_response(['message' => 'Top-up initialized.', 'reference' => $reference, 'authorization_url' => $authUrl]);
    }

    public function paystackWebhook(Request $request): Response
    {
        if (!$this->paystack->validateWebhook($request->getContent(), (string) $request->header('x-paystack-signature', ''))) {
            throw new HttpException(400, 'Invalid signature.');
        }

        $data = $request->json('data') ?? [];
        if ($request->json('event') === 'charge.success' && !empty($data['reference'])) {
            $this->handleSuccessfulPayment((string) $data['reference'], 'paystack', $data);
        }

        return json_response(['status' => 'ok']);
    }

    public function flutterwaveWebhook(Request $request): Response
    {
        if (!$this->flutterwave->validateWebhook((string) $request->header('verif-hash', ''))) {
            throw new HttpException(400, 'Invalid signature.');
        }

        $data = $request->json('data') ?? [];
        if ($request->json('event') === 'charge.completed' && ($data['status'] ?? null) === 'successful' && !empty($data['tx_ref'])) {
            $this->handleSuccessfulPayment((string) $data['tx_ref'], 'flutterwave', $data);
        }

        return json_response(['status' => 'ok']);
    }

    /** Polling fallback: ask the gateway whether the payment went through. */
    public function verifyPayment(Request $request): Response
    {
        $v = $request->validate(['reference' => 'required|string']);

        $intent = PaymentIntent::where('reference', $v['reference'])->where('user_id', $request->user['id'])->first()
            ?? throw new \App\Core\ModelNotFoundException();

        if ($intent['status'] === 'success') {
            return json_response(['message' => 'Payment already verified.', 'intent' => $intent]);
        }

        try {
            if ($intent['gateway'] === 'paystack') {
                $data = $this->paystack->verifyPayment($v['reference']);
                $ok   = ($data['status'] ?? null) === 'success';
            } else {
                $gatewayId = $intent['gateway_response']['id'] ?? null;
                $data = $gatewayId
                    ? $this->flutterwave->verifyPayment((string) $gatewayId)
                    : $this->flutterwave->verifyByReference($v['reference']);
                $ok = ($data['status'] ?? null) === 'successful';
            }
        } catch (\RuntimeException $e) {
            throw new HttpException(500, $e->getMessage());
        }

        if (!$ok) {
            throw new HttpException(422, 'Payment not yet completed.');
        }

        $this->handleSuccessfulPayment($v['reference'], $intent['gateway'], $data);

        return json_response(['message' => 'Payment verified successfully.']);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array{0:array,1:string} gateway data and the URL to send the customer to */
    private function initialize(string $gateway, array $user, float $amount, string $reference, array $meta): array
    {
        if ($gateway === 'paystack') {
            $data = $this->paystack->initializePayment($user['email'], $amount, $reference, $meta);
            return [$data, $data['authorization_url']];
        }

        $data = $this->flutterwave->initializePayment($user['email'], $user['name'], $amount, $reference, $meta);
        return [$data, $data['link']];
    }

    /** Idempotent: the intent row is locked and only processed while still pending. */
    private function handleSuccessfulPayment(string $reference, string $gateway, array $gatewayData): void
    {
        $afterCommit = null;

        DB::transaction(function () use ($reference, $gateway, $gatewayData, &$afterCommit) {
            $row = DB::first("SELECT * FROM payment_intents WHERE reference = ? AND status = 'pending' FOR UPDATE", [$reference]);
            if (!$row) {
                return;
            }
            $intent = PaymentIntent::hydrate($row);

            PaymentIntent::update($intent['id'], [
                'status'           => 'success',
                'gateway_response' => $gatewayData,
                'paid_at'          => now(),
            ]);

            $user = User::findOrFail($intent['user_id']);

            if ($intent['purpose'] === 'order_payment' && $intent['order_id']) {
                $order = Order::findOrFail($intent['order_id']);
                $order = Order::update($order['id'], [
                    'payment_status'    => 'paid',
                    'payment_method'    => $gateway,
                    'payment_reference' => $reference,
                    'status'            => 'confirmed',
                    'paid_at'           => now(),
                ]);

                Transaction::create([
                    'user_id'           => $user['id'],
                    'order_id'          => $order['id'],
                    'reference'         => $reference,
                    'amount'            => $intent['amount'],
                    'type'              => 'payment',
                    'status'            => 'success',
                    'gateway'           => $gateway,
                    'gateway_reference' => $reference,
                    'gateway_response'  => $gatewayData,
                    'description'       => "Payment for order {$order['order_number']}",
                ]);

                $this->loyalty->awardOrderPoints($user['id'], $order['total'], ['id' => $order['id'], 'type' => 'App\\Models\\Order']);

                $afterCommit = fn() => (new OrderPlacedNotification($order))->send($user);
            } elseif ($intent['purpose'] === 'wallet_topup') {
                $wallet = Wallet::forUser($user['id']);
                Wallet::credit($wallet['id'], $intent['amount'], 'topup', "Wallet top-up via {$gateway}", [
                    'gateway'           => $gateway,
                    'gateway_reference' => $reference,
                ]);
            }
        });

        if ($afterCommit) {
            try {
                $afterCommit();
            } catch (\Throwable $e) {
                Logger::error('Post-payment notification failed', ['error' => $e->getMessage()]);
            }
        }
    }
}
