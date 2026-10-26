<?php

declare(strict_types=1);

namespace CableATierra\Datos\Data;

/**
 * Strict numeric coercion for untrusted API values. Never throws.
 *
 * Accepts int, float, or a numeric string (surrounding whitespace allowed); everything
 * else, and anything non-finite (INF, NAN, "1e999"), is null. Nothing is ever coerced
 * to 0: callers must treat null as "unknown".
 */
final class Num
{
    public static function finiteFloat(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = trim($value);
            if (!is_numeric($value)) {
                return null;
            }
            $value = (float) $value;
        } elseif (is_int($value)) {
            $value = (float) $value;
        }

        return is_float($value) && is_finite($value) ? $value : null;
    }

    /** Non-negative integral value that fits in PHP_INT_MAX; fractional, huge or negative is null. */
    public static function nonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        $float = self::finiteFloat($value);
        if ($float === null || $float < 0.0 || $float >= (float) PHP_INT_MAX || floor($float) !== $float) {
            return null;
        }

        return (int) $float;
    }

    /** Finite float within [$min, $max] (inclusive), or null. Out-of-range values are never clamped. */
    public static function floatInRange(mixed $value, float $min, float $max): ?float
    {
        $float = self::finiteFloat($value);

        return $float !== null && $float >= $min && $float <= $max ? $float : null;
    }
}
