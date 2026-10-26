<?php

declare(strict_types=1);

namespace CableATierra\Datos\Format;

use DateTimeImmutable;

/**
 * Deterministic Spanish date text (own month tables, 24-hour clock). Independent of the WordPress
 * locale pack and of the intl extension. Pass dates already converted with LocalTime::at().
 */
final class SpanishDate
{
    private const SHORT = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    private const LONG = [
        'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
    ];

    /** "24 oct" */
    public static function day(DateTimeImmutable $d): string
    {
        return $d->format('j') . ' ' . self::SHORT[(int) $d->format('n') - 1];
    }

    /** "24 oct, 09:10" */
    public static function short(DateTimeImmutable $d): string
    {
        return self::day($d) . ', ' . $d->format('H:i');
    }

    /** "24 de octubre de 2026, 09:10" */
    public static function long(DateTimeImmutable $d): string
    {
        return $d->format('j') . ' de ' . self::LONG[(int) $d->format('n') - 1] . ' de ' . $d->format('Y') . ', ' . $d->format('H:i');
    }
}
