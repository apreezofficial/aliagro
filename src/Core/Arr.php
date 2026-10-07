<?php

namespace App\Core;

/** Dot-notation array helpers (with `*` wildcard expansion for the validator). */
final class Arr
{
    public static function has(array $data, string $path): bool
    {
        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }
            $current = $current[$segment];
        }
        return true;
    }

    public static function get(array $data, string $path, mixed $default = null): mixed
    {
        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    public static function set(array &$data, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $ref      = &$data;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    /**
     * Expand "items.*.product_id" into concrete paths using the data's keys:
     * ["items.0.product_id", "items.1.product_id"]. A wildcard over a missing
     * or non-array value yields the pattern itself with `*` left in place
     * (so "required" can still report it).
     */
    public static function expand(array $data, string $pattern): array
    {
        if (!str_contains($pattern, '*')) {
            return [$pattern];
        }

        $paths = [''];
        foreach (explode('.', $pattern) as $segment) {
            $next = [];
            foreach ($paths as $prefix) {
                if ($segment === '*') {
                    $node = $prefix === '' ? $data : self::get($data, $prefix);
                    if (is_array($node) && $node !== []) {
                        foreach (array_keys($node) as $key) {
                            $next[] = $prefix === '' ? (string) $key : $prefix . '.' . $key;
                        }
                    }
                } else {
                    $next[] = $prefix === '' ? $segment : $prefix . '.' . $segment;
                }
            }
            $paths = $next;
        }
        return $paths;
    }
}
