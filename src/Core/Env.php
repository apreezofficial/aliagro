<?php

namespace App\Core;

/**
 * Minimal .env loader. Real environment variables (e.g. cPanel's
 * "Setup PHP App" panel) take precedence over the file.
 */
final class Env
{
    private static array $vars = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                $end   = strrpos($value, $quote);
                $value = $end > 0 ? substr($value, 1, $end - 1) : substr($value, 1);
                if ($quote === '"') {
                    $value = str_replace(['\\n', '\\"'], ["\n", '"'], $value);
                }
            } else {
                // strip inline comments: KEY=value # comment
                $hash = strpos($value, ' #');
                if ($hash !== false) {
                    $value = rtrim(substr($value, 0, $hash));
                }
            }

            // ${OTHER_VAR} interpolation
            $value = preg_replace_callback('/\$\{([A-Z0-9_]+)\}/i', function ($m) {
                $v = self::raw($m[1]);
                return $v === null ? '' : (string) $v;
            }, $value);

            self::$vars[$key] = $value;
        }
    }

    private static function raw(string $key): mixed
    {
        $real = getenv($key);
        if ($real !== false) {
            return $real;
        }
        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }
        return self::$vars[$key] ?? null;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::raw($key);
        if ($value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}
