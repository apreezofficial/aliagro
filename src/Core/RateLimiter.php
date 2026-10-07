<?php

namespace App\Core;

/** Fixed-window limiter persisted in the rate_limits table (shared across PHP workers). */
final class RateLimiter
{
    /**
     * Count one hit for $key.
     * @return array{hits:int, retry_after:int}
     */
    public static function hit(string $key, int $decaySeconds): array
    {
        $now     = time();
        $expires = $now + $decaySeconds;

        DB::statement(
            'INSERT INTO rate_limits (`key`, hits, expires_at) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE
               hits = IF(expires_at <= ?, 1, hits + 1),
               expires_at = IF(expires_at <= ?, ?, expires_at)',
            [$key, $expires, $now, $now, $expires]
        );

        $row = DB::first('SELECT hits, expires_at FROM rate_limits WHERE `key` = ?', [$key]);

        // Opportunistic cleanup keeps the table tiny.
        if (random_int(1, 100) === 1) {
            DB::statement('DELETE FROM rate_limits WHERE expires_at <= ?', [$now]);
        }

        return ['hits' => (int) $row['hits'], 'retry_after' => max(0, (int) $row['expires_at'] - $now)];
    }
}
