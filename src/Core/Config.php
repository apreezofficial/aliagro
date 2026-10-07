<?php

namespace App\Core;

/** Dot-notation access to the arrays returned by config/*.php. */
final class Config
{
    private static array $loaded = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $file     = array_shift($segments);

        if (!isset(self::$loaded[$file])) {
            $path = base_path("config/{$file}.php");
            self::$loaded[$file] = is_file($path) ? require $path : [];
        }

        $value = self::$loaded[$file];
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
