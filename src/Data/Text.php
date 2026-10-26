<?php

declare(strict_types=1);

namespace CableATierra\Datos\Data;

/**
 * Cleans untrusted display strings. Never throws.
 *
 * Cleaning is NOT escaping: every string that leaves a normalizer must still be
 * escaped for its output context (esc_html, esc_attr, ...).
 */
final class Text
{
    /** Trims, strips control characters and caps to $max characters; null when not a string or empty after cleaning. */
    public static function clean(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $stripped = preg_replace('/\p{Cc}+/u', '', $value);
        if ($stripped === null) {
            return null;
        }

        $text = mb_substr(trim($stripped), 0, $max);
        $text = trim($text);

        return $text === '' ? null : $text;
    }
}
