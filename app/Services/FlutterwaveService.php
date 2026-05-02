<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class FlutterwaveService
{
    private string $secretKey;
    private string $baseUrl = 'https://api.flutterwave.com/v3';

    public function __construct()
    {
        $this->secretKey = config('services.flutterwave.secret_key');
    }

    private function headers(): array
    {
        return [
            'Authorization' => "Bearer {$this->secretKey}",
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Initialize a payment.
     */
    public function initializePayment(string $email, string $name, float $amount, string $reference, array $meta = []): array
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/payments", [
                'tx_ref'          => $reference,
                'amount'          => $amount,
                'currency'        => 'NGN',
                'redirect_url'    => config('app.url') . '/api/payments/flutterwave/callback',
                'customer'        => ['email' => $email, 'name' => $name],
                'meta'            => $meta,
                'customizations'  => [
                    'title'       => 'AliAgro Payment',
                    'description' => 'Farm-to-consumer marketplace',
                    'logo'        => config('app.url') . '/logo.png',
                ],
            ]);

        if (!$response->successful() || $response->json('status') !== 'success') {
            throw new \Exception('Flutterwave initialization failed: ' . $response->json('message'));
        }

        return $response->json('data');
    }

    /**
     * Verify a transaction by ID.
     */
    public function verifyPayment(string $transactionId): array
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/transactions/{$transactionId}/verify");

        if (!$response->successful() || $response->json('status') !== 'success') {
            throw new \Exception('Flutterwave verification failed: ' . $response->json('message'));
        }

        return $response->json('data');
    }

    /**
     * Validate webhook signature.
     */
    public function validateWebhook(string $payload, string $signature): bool
    {
        $expected = hash_hmac('sha256', $payload, config('services.flutterwave.webhook_secret'));
        return hash_equals($expected, $signature);
    }
}
