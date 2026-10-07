<?php

namespace App\Core;

final class Str
{
    private const ALNUM = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /** Cryptographically secure random alphanumeric string. */
    public static function random(int $length = 16): string
    {
        $out = '';
        $max = strlen(self::ALNUM) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALNUM[random_int(0, $max)];
        }
        return $out;
    }

    public static function slug(string $title, string $separator = '-'): string
    {
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) : false;
        $title = $ascii !== false && $ascii !== '' ? $ascii : $title;
        $title = strtolower(str_replace(['&', '@'], [' and ', ' at '], $title));
        $title = preg_replace('/[^a-z0-9]+/', $separator, $title);
        return trim($title, $separator);
    }

    public static function uuid(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** Equivalent of strtoupper(uniqid()): unique-ish, time based. */
    public static function uniqueId(): string
    {
        return strtoupper(uniqid());
    }
}
