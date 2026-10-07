<?php

namespace App\Core;

final class Time
{
    /** MySQL DATETIME (UTC) -> "2026-04-01T10:00:00.000000Z", the way Eloquent serialises. */
    public static function iso(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        if (!$dt) {
            return $value; // already ISO or a date-only value
        }
        return $dt->format('Y-m-d\TH:i:s.u\Z');
    }

    /** Anything strtotime understands (including our ISO output) -> unix timestamp. */
    public static function ts(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $t = strtotime($value);
        return $t === false ? null : $t;
    }
}
