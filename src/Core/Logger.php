<?php

namespace App\Core;

/** Daily log file in storage/logs, one line per entry. */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public static function log(string $level, string $message, array $context = []): void
    {
        $min = self::LEVELS[strtolower((string) env('LOG_LEVEL', 'debug'))] ?? 0;
        if ((self::LEVELS[$level] ?? 0) < $min) {
            return;
        }

        $dir = storage_path('logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            "[%s] %s.%s: %s%s\n",
            gmdate('Y-m-d H:i:s'),
            (string) env('APP_ENV', 'production'),
            strtoupper($level),
            $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : ''
        );
        @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function error(string $message, array $context = []): void   { self::log('error', $message, $context); }
    public static function warning(string $message, array $context = []): void { self::log('warning', $message, $context); }
    public static function info(string $message, array $context = []): void    { self::log('info', $message, $context); }
    public static function debug(string $message, array $context = []): void   { self::log('debug', $message, $context); }
}
