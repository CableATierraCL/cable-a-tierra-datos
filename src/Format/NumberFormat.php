<?php

declare(strict_types=1);

namespace CableATierra\Datos\Format;

/**
 * es-CL number text ("1.234.567"). Deterministic: no intl, no locale. Missing values (null) are
 * never formatted, so callers cannot turn "no data" into "0" by accident.
 */
final class NumberFormat
{
    /** Integer with "." as thousands separator, rounded half up. */
    public static function integer(int|float|null $n): ?string
    {
        return $n === null ? null : number_format(round($n), 0, ',', '.');
    }

    /** "12.345 ha" (non-breaking space), "<1 ha" for 0 < x < 1, "0 ha" for zero. */
    public static function hectares(?float $ha): ?string
    {
        if ($ha === null) {
            return null;
        }
        $text = $ha > 0 && $ha < 1 ? '<1' : (string) self::integer(max(0.0, $ha));

        return $text . "\u{00A0}ha";
    }
}
