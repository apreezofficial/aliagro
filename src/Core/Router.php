<?php

namespace App\Core;

/**
 * Small regex router. Routes are matched in registration order (first match wins),
 * supports {param} segments, nested groups with prefix + middleware.
 *
 *   $router->group(['prefix' => 'admin', 'middleware' => ['auth', 'role:admin']], function ($r) { ... });
 *   $router->get('products/{product}', [ProductController::class, 'show']);
 */
final class Router
{
    /** @var array<int, array{method:string, regex:string, params:string[], handler:array, middleware:string[]}> */
    private array $routes = [];
    private array $stack  = [['prefix' => '', 'middleware' => []]];

    public function group(array $attributes, callable $callback): void
    {
        $parent = end($this->stack);
        $prefix = trim($parent['prefix'] . '/' . trim($attributes['prefix'] ?? '', '/'), '/');
        $this->stack[] = [
            'prefix'     => $prefix,
            'middleware' => array_merge($parent['middleware'], (array) ($attributes['middleware'] ?? [])),
        ];
        $callback($this);
        array_pop($this->stack);
    }

    public function get(string $uri, array $handler): void    { $this->add('GET', $uri, $handler); }
    public function post(string $uri, array $handler): void   { $this->add('POST', $uri, $handler); }
    public function put(string $uri, array $handler): void    { $this->add('PUT', $uri, $handler); }
    public function patch(string $uri, array $handler): void  { $this->add('PATCH', $uri, $handler); }
    public function delete(string $uri, array $handler): void { $this->add('DELETE', $uri, $handler); }

    /** index/store/show/update/destroy for a controller, like Route::apiResource. */
    public function apiResource(string $uri, string $controller, string $param = 'id'): void
    {
        $this->get($uri, [$controller, 'index']);
        $this->post($uri, [$controller, 'store']);
        $this->get("{$uri}/{{$param}}", [$controller, 'show']);
        $this->put("{$uri}/{{$param}}", [$controller, 'update']);
        $this->patch("{$uri}/{{$param}}", [$controller, 'update']);
        $this->delete("{$uri}/{{$param}}", [$controller, 'destroy']);
    }

    private function add(string $method, string $uri, array $handler): void
    {
        $ctx  = end($this->stack);
        $path = trim($ctx['prefix'] . '/' . trim($uri, '/'), '/');
        $params = [];
        $regex  = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $path);

        $this->routes[] = [
            'method'     => $method,
            'regex'      => '#^/' . $regex . '$#',
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => $ctx['middleware'],
        ];
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            if ($route['method'] !== $request->method) {
                $allowed[] = $route['method'];
                continue;
            }

            array_shift($m);
            $args = array_map('rawurldecode', $m);

            $core = function (Request $req) use ($route, $args) {
                [$class, $method] = $route['handler'];
                $result = (new $class())->$method($req, ...$args);
                return $result instanceof Response ? $result : Response::json($result);
            };

            return $this->runMiddleware($route['middleware'], $request, $core);
        }

        if ($allowed) {
            throw new HttpException(405, 'The ' . $request->method . ' method is not supported for this route. Supported methods: '
                . implode(', ', array_unique($allowed)) . '.', [], ['Allow' => implode(', ', array_unique($allowed))]);
        }

        throw new HttpException(404, 'The route ' . ltrim($request->path, '/') . ' could not be found.');
    }

    private function runMiddleware(array $names, Request $request, callable $core): Response
    {
        $pipeline = array_reduce(
            array_reverse($names),
            function (callable $next, string $name) {
                [$alias, $argString] = array_pad(explode(':', $name, 2), 2, '');
                $class = self::MIDDLEWARE[$alias] ?? throw new \LogicException("Unknown middleware [{$alias}]");
                $args  = $argString === '' ? [] : explode(',', $argString);
                return fn(Request $req) => (new $class())->handle($req, $next, ...$args);
            },
            $core
        );

        return $pipeline($request);
    }

    private const MIDDLEWARE = [
        'auth'     => \App\Middleware\Authenticate::class,
        'role'     => \App\Middleware\EnsureRole::class,
        'kyc'      => \App\Middleware\EnsureKycApproved::class,
        'throttle' => \App\Middleware\Throttle::class,
    ];
}
