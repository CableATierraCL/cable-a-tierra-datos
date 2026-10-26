<?php

declare(strict_types=1);

namespace CableATierra\Datos\Summary;

/**
 * Plain-language copy for NoFires alert drivers. Returns null for a missing or unknown driver so
 * a raw enum value can never reach the reader.
 */
final class DriverCopy
{
    /**
     * @param ?string $dateLabel e.g. "24 oct" when the data is not from today; replaces "hoy"
     */
    public static function phrase(?string $driverKey, bool $plural, ?string $dateLabel): ?string
    {
        $text = match ($driverKey) {
            'FIRMS_TODAY' => $plural ? 'focos de calor detectados hoy' : 'foco de calor detectado hoy',
            'FIRMS_CLUSTER' => 'varios focos de calor en las últimas 48 h',
            'FIRMS_FRP' => 'focos de calor de alta intensidad',
            'FWI' => 'condiciones meteorológicas extremas',
            'SCORE' => 'puntaje de riesgo muy alto',
            default => null,
        };

        return $text !== null && $dateLabel !== null ? str_replace(' hoy', ' el ' . $dateLabel, $text) : $text;
    }

    /** "por un foco de calor detectado hoy" / "por focos ..."; null without a known driver. */
    public static function clause(?string $driverKey, bool $plural, ?string $dateLabel): ?string
    {
        $phrase = self::phrase($driverKey, $plural, $dateLabel);
        if ($phrase === null) {
            return null;
        }

        return 'por ' . ($driverKey === 'FIRMS_TODAY' && !$plural ? 'un ' : '') . $phrase;
    }
}
