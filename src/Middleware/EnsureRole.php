<?php

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/** Usage: 'role:admin' or 'role:farmer,admin' */
final class EnsureRole
{
    public function handle(Request $request, callable $next, string ...$roles): Response
    {
        if (!$request->user || !in_array($request->user['role'], $roles, true)) {
            throw new HttpException(403, 'Unauthorized. Insufficient role.');
        }

        return $next($request);
    }
}
