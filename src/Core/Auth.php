<?php

namespace App\Core;

use App\Models\User;

/**
 * Bearer-token authentication, wire-compatible with Laravel Sanctum:
 * tokens are "{id}|{40 random chars}" and only sha256(random part) is stored
 * in personal_access_tokens, so tokens already issued by the Laravel app keep working.
 */
final class Auth
{
    public const TOKENABLE_TYPE = 'App\\Models\\User';

    /** @return string the plain-text token to hand to the client */
    public static function createToken(int $userId, string $name = 'auth_token'): string
    {
        $plain = Str::random(40);
        $ts    = now();
        $id    = DB::insert('personal_access_tokens', [
            'tokenable_type' => self::TOKENABLE_TYPE,
            'tokenable_id'   => $userId,
            'name'           => $name,
            'token'          => hash('sha256', $plain),
            'abilities'      => '["*"]',
            'created_at'     => $ts,
            'updated_at'     => $ts,
        ]);

        return $id . '|' . $plain;
    }

    /** Resolve "{id}|{plain}" to [user, tokenRow] or null. */
    public static function resolve(string $bearer): ?array
    {
        if (!str_contains($bearer, '|')) {
            return null;
        }
        [$id, $plain] = explode('|', $bearer, 2);
        if (!ctype_digit($id) || $plain === '') {
            return null;
        }

        $token = DB::first('SELECT * FROM personal_access_tokens WHERE id = ? AND tokenable_type = ?', [$id, self::TOKENABLE_TYPE]);
        if (!$token || !hash_equals($token['token'], hash('sha256', $plain))) {
            return null;
        }
        if ($token['expires_at'] !== null && strtotime($token['expires_at'] . ' UTC') < time()) {
            return null;
        }

        $user = User::find($token['tokenable_id']);
        if (!$user) {
            return null;
        }

        // Avoid a write on every request: touch at most once a minute.
        if ($token['last_used_at'] === null || strtotime($token['last_used_at'] . ' UTC') < time() - 60) {
            DB::update('personal_access_tokens', ['last_used_at' => now()], 'id = ?', [$token['id']]);
        }

        return [$user, $token];
    }

    public static function revokeCurrent(?array $token): void
    {
        if ($token) {
            DB::delete('personal_access_tokens', 'id = ?', [$token['id']]);
        }
    }

    public static function revokeAll(int $userId): void
    {
        DB::delete('personal_access_tokens', 'tokenable_type = ? AND tokenable_id = ?', [self::TOKENABLE_TYPE, $userId]);
    }

    // ── Passwords (bcrypt, same $2y$ hashes as Laravel) ──────────────────

    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => (int) env('BCRYPT_ROUNDS', 12)]);
    }

    public static function check(string $plain, ?string $hash): bool
    {
        return $hash !== null && $hash !== '' && password_verify($plain, $hash);
    }

    // ── Signed URLs (email verification), same algorithm as Laravel ──────

    public static function signUrl(string $url, int $expiresAt): string
    {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'expires=' . $expiresAt;
        return $url . '&signature=' . hash_hmac('sha256', $url, self::appKey());
    }

    public static function hasValidSignature(string $fullUrl): bool
    {
        $parts = parse_url($fullUrl);
        parse_str($parts['query'] ?? '', $query);
        $signature = $query['signature'] ?? null;
        $expires   = (int) ($query['expires'] ?? 0);
        if (!$signature || $expires < time()) {
            return false;
        }

        unset($query['signature']);
        $base = ($parts['scheme'] ?? 'http') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $parts['path'];
        $canonical = $base . ($query ? '?' . http_build_query($query) : '');

        return hash_equals(hash_hmac('sha256', $canonical, self::appKey()), $signature);
    }

    private static function appKey(): string
    {
        $key = (string) env('APP_KEY', '');
        if ($key === '') {
            throw new \RuntimeException('APP_KEY is not set. Run: php bin/key-generate.php');
        }
        return str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;
    }
}
