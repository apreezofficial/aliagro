<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PaystackService
{
    private string $secretKey;
    private string $baseUrl = 'https://api.paystack.co';

    public function __construct()
    {
        $this->secretKey = config('services.paystack.secret_key');
    }

    private function headers(): array
    {
        return [
            'Authorization' => "Bearer {$this->secretKey}",
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Initialize a payment and get authorization URL.
     */
    public function initializePayment(string $email, float $amount, string $reference, array $metadata = []): array
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/transaction/initialize", [
                'email'     => $email,
                'amount'    => (int) ($amount * 100), // kobo
                'reference' => $reference,
                'metadata'  => $metadata,
                'callback_url' => config('app.url') . '/api/payments/paystack/callback',
            ]);

        if (!$response->successful() || !$response->json('status')) {
            throw new \Exception('Paystack initialization failed: ' . $response->json('message'));
        }

        return $response->json('data');
    }

    /**
     * Verify a payment by reference.
     */
    public function verifyPayment(string $reference): array
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/transaction/verify/{$reference}");

        if (!$response->successful() || !$response->json('status')) {
            throw new \Exception('Paystack verification failed: ' . $response->json('message'));
        }

        return $response->json('data');
    }

    /**
     * Validate webhook signature.
     */
    public function validateWebhook(string $payload, string $signature): bool
    {
        $expected = hash_hmac('sha512', $payload, $this->secretKey);
        return hash_equals($expected, $signature);
    }
}
