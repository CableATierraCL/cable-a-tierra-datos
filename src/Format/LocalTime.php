<?php

declare(strict_types=1);

namespace CableATierra\Datos\Format;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The single UTC -> America/Santiago converter. Uses the IANA zone (never a fixed offset), so the
 * April and September clock changes are handled by tzdata.
 */
final class LocalTime
{
    public const ZONE = 'America/Santiago';

    /**
     * Parses exactly "Y-m-d\TH:i:s\Z" (the normalized form) to Unix seconds; null for anything
     * else, including empty strings (which PHP would otherwise read as "now") and impossible
     * dates such as 2026-02-31.
     */
    public static function parseUtc(string $iso): ?int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $iso, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d\TH:i:s\Z') === $iso ? $date->getTimestamp() : null;
    }

    public static function at(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone(self::ZONE));
    }

    /** Unix seconds -> "2026-10-25T14:05:00Z". */
    public static function utcIso(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))->format('Y-m-d\TH:i:s\Z');
    }

    /** Calendar day in Chile, "Y-m-d". */
    public static function dateKey(int $timestamp): string
    {
        return self::at($timestamp)->format('Y-m-d');
    }
}
