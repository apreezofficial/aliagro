<?php

namespace App\Services;

use App\Core\Http;

class FlutterwaveService
{
    private const BASE_URL = 'https://api.flutterwave.com/v3';

    private function headers(): array
    {
        return ['Authorization: Bearer ' . config('services.flutterwave.secret_key')];
    }

    /** Initialize a payment and return Flutterwave's `data` (contains `link`). */
    public function initializePayment(string $email, string $name, float $amount, string $reference, array $meta = []): array
    {
        $res = Http::postJson(self::BASE_URL . '/payments', [
            'tx_ref'         => $reference,
            'amount'         => $amount,
            'currency'       => 'NGN',
            'redirect_url'   => config('app.url') . '/api/payments/flutterwave/callback',
            'customer'       => ['email' => $email, 'name' => $name],
            'meta'           => (object) $meta,
            'customizations' => [
                'title'       => 'AliAgro Payment',
                'description' => 'Farm-to-consumer marketplace',
                'logo'        => config('app.url') . '/logo.png',
            ],
        ], $this->headers());

        if ($res['status'] < 200 || $res['status'] >= 300 || ($res['json']['status'] ?? null) !== 'success') {
            throw new \RuntimeException('Flutterwave initialization failed: ' . ($res['json']['message'] ?? 'unknown error'));
        }

        return $res['json']['data'];
    }

    /** Verify by Flutterwave transaction id. */
    public function verifyPayment(string $transactionId): array
    {
        return $this->verify(self::BASE_URL . '/transactions/' . rawurlencode($transactionId) . '/verify');
    }

    /** Verify by our own tx_ref (no gateway id is stored at initialization time). */
    public function verifyByReference(string $txRef): array
    {
        return $this->verify(self::BASE_URL . '/transactions/verify_by_reference?tx_ref=' . rawurlencode($txRef));
    }

    private function verify(string $url): array
    {
        $res = Http::getJson($url, $this->headers());

        if ($res['status'] < 200 || $res['status'] >= 300 || ($res['json']['status'] ?? null) !== 'success') {
            throw new \RuntimeException('Flutterwave verification failed: ' . ($res['json']['message'] ?? 'unknown error'));
        }

        return $res['json']['data'];
    }

    /** Flutterwave sends the secret hash you configured in the `verif-hash` header. */
    public function validateWebhook(string $signature): bool
    {
        $secret = (string) config('services.flutterwave.webhook_secret');
        return $secret !== '' && hash_equals($secret, $signature);
    }
}
