<?php

declare(strict_types=1);

namespace CableATierra\Datos\Summary;

/**
 * Plain-language copy for NoFires alert drivers. Returns null for a missing or unknown driver so
 * a raw enum value can never reach the reader.
 *
 * Each driver has an explicit [singular, plural] clause. `{cuando}` is the only slot: it is filled
 * with "hoy" or "el 24 oct" (and dropped when the date is unknown); templates without it ignore it.
 */
final class DriverCopy
{
    private const TEMPLATES = [
        'FIRMS_TODAY' => ['por un foco de calor detectado{cuando}', 'por focos de calor detectados{cuando}'],
        'FIRMS_CLUSTER' => ['por varios focos de calor en las últimas 48 h', 'por varios focos de calor en las últimas 48 h'],
        'FIRMS_FRP' => ['por focos de calor de alta intensidad', 'por focos de calor de alta intensidad'],
        'FWI' => ['por condiciones meteorológicas extremas', 'por condiciones meteorológicas extremas'],
        'SCORE' => ['por un puntaje de riesgo muy alto', 'por un puntaje de riesgo muy alto'],
    ];

    /**
     * @param ?string $cuando "hoy", "el 24 oct" or null when the date is unknown
     * @return ?string e.g. "por un foco de calor detectado hoy"; null without a known driver
     */
    public static function clause(?string $driverKey, bool $plural, ?string $cuando): ?string
    {
        $template = $driverKey === null ? null : (self::TEMPLATES[$driverKey] ?? null);
        if ($template === null) {
            return null;
        }

        return str_replace('{cuando}', $cuando === null ? '' : ' ' . $cuando, $template[$plural ? 1 : 0]);
    }
}
