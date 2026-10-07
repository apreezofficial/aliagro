<?php

namespace App\Services;

use App\Core\Http;

class PaystackService
{
    private const BASE_URL = 'https://api.paystack.co';

    private function secret(): string
    {
        return (string) config('services.paystack.secret_key');
    }

    private function headers(): array
    {
        return ['Authorization: Bearer ' . $this->secret()];
    }

    /** Initialize a payment and return Paystack's `data` (authorization_url, reference, ...). */
    public function initializePayment(string $email, float $amount, string $reference, array $metadata = []): array
    {
        $res = Http::postJson(self::BASE_URL . '/transaction/initialize', [
            'email'        => $email,
            'amount'       => (int) round($amount * 100), // kobo
            'reference'    => $reference,
            'metadata'     => (object) $metadata,
            'callback_url' => config('app.url') . '/api/payments/paystack/callback',
        ], $this->headers());

        if ($res['status'] < 200 || $res['status'] >= 300 || empty($res['json']['status'])) {
            throw new \RuntimeException('Paystack initialization failed: ' . ($res['json']['message'] ?? 'unknown error'));
        }

        return $res['json']['data'];
    }

    public function verifyPayment(string $reference): array
    {
        $res = Http::getJson(self::BASE_URL . '/transaction/verify/' . rawurlencode($reference), $this->headers());

        if ($res['status'] < 200 || $res['status'] >= 300 || empty($res['json']['status'])) {
            throw new \RuntimeException('Paystack verification failed: ' . ($res['json']['message'] ?? 'unknown error'));
        }

        return $res['json']['data'];
    }

    public function validateWebhook(string $payload, string $signature): bool
    {
        if ($this->secret() === '' || $signature === '') {
            return false;
        }
        return hash_equals(hash_hmac('sha512', $payload, $this->secret()), $signature);
    }
}
