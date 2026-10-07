<?php

namespace App\Core;

/**
 * Front controller logic: CORS, routing, and turning every exception
 * into the same JSON error shapes the Laravel API produced.
 */
final class Kernel
{
    public static function handleRequest(Request $request): Response
    {
        try {
            if ($request->method === 'OPTIONS') {
                return self::withCors(new Response(null, 204), $request);
            }

            $router = new Router();
            require base_path('routes/api.php');

            $response = $router->dispatch($request);
        } catch (HttpException $e) {
            $response = Response::json(['message' => $e->getMessage()] + $e->extra, $e->status, $e->headers);
        } catch (ValidationException $e) {
            $response = Response::json(['message' => 'Validation failed.', 'errors' => $e->errors], 422);
        } catch (AuthenticationException $e) {
            $response = Response::json(['message' => 'Unauthenticated.'], 401);
        } catch (ModelNotFoundException $e) {
            $response = Response::json(['message' => 'Resource not found.'], 404);
        } catch (\Throwable $e) {
            Logger::error($e->getMessage(), ['exception' => get_class($e), 'file' => $e->getFile() . ':' . $e->getLine(), 'trace' => $e->getTraceAsString()]);
            $payload = ['message' => 'Server Error'];
            if (env('APP_DEBUG', false)) {
                $payload = [
                    'message'   => $e->getMessage(),
                    'exception' => get_class($e),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ];
            }
            $response = Response::json($payload, 500);
        }

        return self::withCors($response, $request);
    }

    private static function withCors(Response $response, Request $request): Response
    {
        $origins = (string) env('CORS_ALLOWED_ORIGINS', '*');
        $origin  = (string) $request->header('origin', '');
        $allow   = '*';

        if ($origins !== '*') {
            $list  = array_map('trim', explode(',', $origins));
            $allow = in_array($origin, $list, true) ? $origin : ($list[0] ?? '');
            $response->headers['Vary'] = 'Origin';
        }

        $response->headers += [
            'Access-Control-Allow-Origin'  => $allow,
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => $request->header('access-control-request-headers', 'Authorization, Content-Type, Accept, X-Requested-With'),
            'Access-Control-Expose-Headers' => 'X-RateLimit-Limit, X-RateLimit-Remaining, Retry-After',
        ];

        return $response;
    }
}
