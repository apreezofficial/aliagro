<?php

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;

/**
 * 'throttle:auth' => 10 requests / minute / IP (the limiter the Laravel app
 * applied to the public auth endpoints).
 */
final class Throttle
{
    private const LIMITERS = [
        'auth' => ['max' => 10, 'decay' => 60],
        'api'  => ['max' => 60, 'decay' => 60],
    ];

    public function handle(Request $request, callable $next, string $limiter = 'api'): Response
    {
        $cfg = self::LIMITERS[$limiter] ?? self::LIMITERS['api'];
        $key = sha1($limiter . '|' . $request->ip());
        $res = RateLimiter::hit($key, $cfg['decay']);

        $headers = [
            'X-RateLimit-Limit'     => (string) $cfg['max'],
            'X-RateLimit-Remaining' => (string) max(0, $cfg['max'] - $res['hits']),
        ];

        if ($res['hits'] > $cfg['max']) {
            throw new HttpException(429, 'Too Many Attempts.', [], $headers + ['Retry-After' => (string) $res['retry_after']]);
        }

        return $next($request)->withHeaders($headers);
    }
}
