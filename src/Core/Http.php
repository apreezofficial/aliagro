<?php

namespace App\Core;

/** Minimal cURL client for the payment gateways and Google OAuth. */
final class Http
{
    /**
     * @return array{status:int, body:string, json:?array}
     */
    public static function request(string $method, string $url, array $headers = [], array|string|null $body = null, int $timeout = 30): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP request failed: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $json = json_decode($response, true);
        return ['status' => $status, 'body' => $response, 'json' => is_array($json) ? $json : null];
    }

    public static function getJson(string $url, array $headers = []): array
    {
        return self::request('GET', $url, [...$headers, 'Accept: application/json']);
    }

    public static function postJson(string $url, array $payload, array $headers = []): array
    {
        return self::request('POST', $url, array_merge($headers, ['Content-Type: application/json', 'Accept: application/json']),
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function postForm(string $url, array $fields, array $headers = []): array
    {
        return self::request('POST', $url, array_merge($headers, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json']),
            http_build_query($fields));
    }
}
