<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\Transaction;
use App\Notifications\OrderPlacedNotification;
use App\Services\FlutterwaveService;
use App\Services\LoyaltyService;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function __construct(
        private PaystackService    $paystack,
        private FlutterwaveService $flutterwave,
        private LoyaltyService     $loyalty,
    ) {}

    /**
     * Initialize payment for an order.
     */
    public function initializeOrderPayment(Request $request): JsonResponse
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'gateway'  => 'required|in:paystack,flutterwave',
        ]);

        $order = Order::findOrFail($request->order_id);
        $user  = $request->user();

        if ($order->consumer_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($order->payment_status === 'paid') {
            return response()->json(['message' => 'Order already paid.'], 422);
        }

        $reference = 'ALG-PAY-' . strtoupper(Str::random(12));

        try {
            if ($request->gateway === 'paystack') {
                $data = $this->paystack->initializePayment(
                    $user->email,
                    $order->total,
                    $reference,
                    ['order_id' => $order->id, 'user_id' => $user->id]
                );
                $authUrl = $data['authorization_url'];
            } else {
                $data    = $this->flutterwave->initializePayment(
                    $user->email,
                    $user->name,
                    $order->total,
                    $reference,
                    ['order_id' => $order->id]
                );
                $authUrl = $data['link'];
            }

            PaymentIntent::create([
                'user_id'           => $user->id,
                'order_id'          => $order->id,
                'reference'         => $reference,
                'amount'            => $order->total,
                'gateway'           => $request->gateway,
                'purpose'           => 'order_payment',
                'authorization_url' => $authUrl,
                'gateway_response'  => $data,
            ]);

            return response()->json([
                'message'           => 'Payment initialized.',
                'reference'         => $reference,
                'authorization_url' => $authUrl,
                'gateway'           => $request->gateway,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Initialize wallet top-up.
     */
    public function initializeTopup(Request $request): JsonResponse
    {
        $request->validate([
            'amount'  => 'required|numeric|min:100',
            'gateway' => 'required|in:paystack,flutterwave',
        ]);

        $user      = $request->user();
        $reference = 'ALG-TOP-' . strtoupper(Str::random(12));

        try {
            if ($request->gateway === 'paystack') {
                $data    = $this->paystack->initializePayment($user->email, $request->amount, $reference);
                $authUrl = $data['authorization_url'];
            } else {
                $data    = $this->flutterwave->initializePayment($user->email, $user->name, $request->amount, $reference);
                $authUrl = $data['link'];
            }

            PaymentIntent::create([
                'user_id'           => $user->id,
                'reference'         => $reference,
                'amount'            => $request->amount,
                'gateway'           => $request->gateway,
                'purpose'           => 'wallet_topup',
                'authorization_url' => $authUrl,
                'gateway_response'  => $data,
            ]);

            return response()->json([
                'message'           => 'Top-up initialized.',
                'reference'         => $reference,
                'authorization_url' => $authUrl,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Paystack webhook handler.
     */
    public function paystackWebhook(Request $request): JsonResponse
    {
        $signature = $request->header('x-paystack-signature', '');

        if (!$this->paystack->validateWebhook($request->getContent(), $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $event = $request->json('event');
        $data  = $request->json('data');

        if ($event === 'charge.success') {
            $this->handleSuccessfulPayment($data['reference'], 'paystack', $data);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Flutterwave webhook handler.
     */
    public function flutterwaveWebhook(Request $request): JsonResponse
    {
        $signature = $request->header('verif-hash', '');

        if ($signature !== config('services.flutterwave.webhook_secret')) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $event = $request->json('event');
        $data  = $request->json('data');

        if ($event === 'charge.completed' && $data['status'] === 'successful') {
            $this->handleSuccessfulPayment($data['tx_ref'], 'flutterwave', $data);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Verify payment manually (polling fallback).
     */
    public function verifyPayment(Request $request): JsonResponse
    {
        $request->validate(['reference' => 'required|string']);

        $intent = PaymentIntent::where('reference', $request->reference)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($intent->status === 'success') {
            return response()->json(['message' => 'Payment already verified.', 'intent' => $intent]);
        }

        try {
            if ($intent->gateway === 'paystack') {
                $data   = $this->paystack->verifyPayment($request->reference);
                $status = $data['status'] === 'success';
            } else {
                $data   = $this->flutterwave->verifyPayment($intent->gateway_response['id'] ?? '');
                $status = $data['status'] === 'successful';
            }

            if ($status) {
                $this->handleSuccessfulPayment($request->reference, $intent->gateway, $data);
                return response()->json(['message' => 'Payment verified successfully.']);
            }

            return response()->json(['message' => 'Payment not yet completed.'], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    private function handleSuccessfulPayment(string $reference, string $gateway, array $gatewayData): void
    {
        $intent = PaymentIntent::where('reference', $reference)->where('status', 'pending')->first();
        if (!$intent) return;

        DB::transaction(function () use ($intent, $gateway, $gatewayData) {
            $intent->update([
                'status'           => 'success',
                'gateway_response' => $gatewayData,
                'paid_at'          => now(),
            ]);

            $user = $intent->user;

            if ($intent->purpose === 'order_payment' && $intent->order_id) {
                $order = $intent->order;
                $order->update([
                    'payment_status'    => 'paid',
                    'payment_method'    => $gateway,
                    'payment_reference' => $reference,
                    'status'            => 'confirmed',
                    'paid_at'           => now(),
                ]);

                // Record transaction
                Transaction::create([
                    'user_id'            => $user->id,
                    'order_id'           => $order->id,
                    'reference'          => $reference,
                    'amount'             => $intent->amount,
                    'type'               => 'payment',
                    'status'             => 'success',
                    'gateway'            => $gateway,
                    'gateway_reference'  => $reference,
                    'gateway_response'   => $gatewayData,
                    'description'        => "Payment for order {$order->order_number}",
                ]);

                // Award loyalty points
                $this->loyalty->awardOrderPoints($user, $order->total, $order);

                // Notify consumer
                $user->notify(new OrderPlacedNotification($order));

            } elseif ($intent->purpose === 'wallet_topup') {
                $wallet = $user->wallet ?? $user->wallet()->create(['balance' => 0]);
                $wallet->credit($intent->amount, 'topup', "Wallet top-up via {$gateway}", [
                    'gateway'           => $gateway,
                    'gateway_reference' => $reference,
                ]);
            }
        });
    }
}
