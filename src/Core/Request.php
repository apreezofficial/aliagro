<?php

namespace App\Core;

final class Request
{
    private static ?Request $current = null;

    /** Authenticated user row (set by the Authenticate middleware). */
    public ?array $user = null;
    /** personal_access_tokens row of the bearer token in use. */
    public ?array $token = null;

    private array $input;
    private array $files;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        array $body,
        array $files,
        public readonly array $headers,
        public readonly array $server,
        private readonly string $rawBody,
    ) {
        $this->input = self::normalize($body + $query);
        $this->files = $files;
    }

    public static function current(): self
    {
        return self::$current ??= self::capture();
    }

    public static function capture(): self
    {
        $server  = $_SERVER;
        $method  = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $raw     = file_get_contents('php://input') ?: '';
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }
        if (!isset($headers['authorization']) && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                $headers[strtolower($k)] = $headers[strtolower($k)] ?? $v;
            }
        }
        if (!isset($headers['authorization']) && isset($server['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = $server['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $body = [];
        $type = strtolower($headers['content-type'] ?? '');
        if (str_contains($type, 'application/json')) {
            $decoded = json_decode($raw, true);
            $body    = is_array($decoded) ? $decoded : [];
        } elseif ($method === 'POST') {
            $body = $_POST;
        } elseif ($raw !== '' && str_contains($type, 'application/x-www-form-urlencoded')) {
            parse_str($raw, $body);
        }

        // Method spoofing, like Laravel: POST + _method=PUT
        if ($method === 'POST' && isset($body['_method'])) {
            $spoof = strtoupper((string) $body['_method']);
            if (in_array($spoof, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $spoof;
            }
        }

        $uri  = parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uri  = rawurldecode($uri);
        // Support installs in a sub-directory (e.g. public_html/api/public).
        $base = rtrim(str_replace('\\', '/', dirname($server['SCRIPT_NAME'] ?? '')), '/');
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base)) ?: '/';
        }
        $path = '/' . trim($uri, '/');

        $request = new self($method, $path, $_GET, $body, self::normalizeFiles($_FILES), $headers, $server, $raw);
        self::$current = $request;
        return $request;
    }

    /** Builds a request programmatically (used by the test harness). */
    public static function make(string $method, string $path, array $data = [], array $headers = [], array $files = [], string $raw = ''): self
    {
        $query = [];
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
            parse_str($qs, $query);
        }
        $request = new self(strtoupper($method), '/' . trim($path, '/'), $query, $data, $files,
            array_change_key_case($headers), ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'], $raw);
        self::$current = $request;
        return $request;
    }

    /** Trim strings and convert '' to null (Laravel's default middleware). */
    private static function normalize(array $data): array
    {
        $skipTrim = ['password', 'password_confirmation', 'current_password', 'db_password', 'admin_password', 'admin_password_confirmation'];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::normalize($value);
            } elseif (is_string($value)) {
                $value = in_array($key, $skipTrim, true) ? $value : trim($value);
                $data[$key] = $value === '' ? null : $value;
            }
        }
        return $data;
    }

    /** Turn $_FILES into UploadedFile objects, preserving nested field names (images[]). */
    private static function normalizeFiles(array $files): array
    {
        $out = [];
        foreach ($files as $field => $spec) {
            $out[$field] = self::buildFile($spec['name'], $spec['tmp_name'], $spec['size'], $spec['error']);
            if ($out[$field] === null || $out[$field] === []) {
                unset($out[$field]);
            }
        }
        return $out;
    }

    private static function buildFile(mixed $name, mixed $tmp, mixed $size, mixed $error): UploadedFile|array|null
    {
        if (is_array($name)) {
            $list = [];
            foreach ($name as $i => $_) {
                $f = self::buildFile($name[$i], $tmp[$i], $size[$i], $error[$i]);
                if ($f !== null && $f !== []) {
                    $list[$i] = $f;
                }
            }
            return $list;
        }
        if ((int) $error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        return new UploadedFile((string) $name, (string) $tmp, (int) $size, (int) $error);
    }

    // ── Accessors ────────────────────────────────────────────────────────

    /** All input + uploaded files, the way $request->all() behaves. */
    public function all(): array
    {
        return array_replace($this->input, $this->files);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->all(), $key, $default);
    }

    /** Magic-style access to a field, mimicking $request->field. */
    public function __get(string $name): mixed
    {
        return $this->input($name);
    }

    public function has(string $key): bool
    {
        return Arr::has($this->all(), $key);
    }

    public function filled(string $key): bool
    {
        $v = $this->input($key);
        return $v !== null && $v !== '' && $v !== [];
    }

    public function file(string $key): UploadedFile|array|null
    {
        return $this->files[$key] ?? null;
    }

    public function hasFile(string $key): bool
    {
        $f = $this->files[$key] ?? null;
        return $f instanceof UploadedFile || (is_array($f) && $f !== []);
    }

    /** JSON body value (used by webhooks). */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        $decoded = json_decode($this->rawBody, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        return $key === null ? $decoded : Arr::get($decoded, $key, $default);
    }

    public function getContent(): string
    {
        return $this->rawBody;
    }

    public function header(string $name, mixed $default = null): mixed
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $header = (string) $this->header('authorization', '');
        if (stripos($header, 'bearer ') === 0) {
            $token = trim(substr($header, 7));
            return $token !== '' ? $token : null;
        }
        return null;
    }

    public function ip(): string
    {
        if (env('TRUST_PROXY', false) && !empty($this->server['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $this->server['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function userAgent(): ?string
    {
        return $this->header('user-agent');
    }

    public function user(): ?array
    {
        return $this->user;
    }

    /** Absolute URL of the current path without the query string. */
    public function url(): string
    {
        $https  = ($this->server['HTTPS'] ?? '') !== '' && ($this->server['HTTPS'] ?? '') !== 'off'
            || strtolower($this->header('x-forwarded-proto', '')) === 'https';
        $host   = $this->server['HTTP_HOST'] ?? 'localhost';
        $base   = rtrim(str_replace('\\', '/', dirname($this->server['SCRIPT_NAME'] ?? '')), '/');
        return ($https ? 'https' : 'http') . '://' . $host . $base . $this->path;
    }

    public function validate(array $rules): array
    {
        return Validator::validate($this->all(), $rules);
    }
}
