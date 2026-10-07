<?php

namespace App\Middleware;

use App\Core\Auth;
use App\Core\AuthenticationException;
use App\Core\Request;
use App\Core\Response;

/** Equivalent of auth:sanctum for bearer tokens. */
final class Authenticate
{
    public function handle(Request $request, callable $next): Response
    {
        $bearer   = $request->bearerToken();
        $resolved = $bearer ? Auth::resolve($bearer) : null;

        if (!$resolved) {
            throw new AuthenticationException();
        }

        [$request->user, $request->token] = $resolved;

        return $next($request);
    }
}
