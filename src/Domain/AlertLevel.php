<?php

declare(strict_types=1);

namespace CableATierra\Datos\Domain;

/**
 * NoFires alert levels. Unknown covers any string the API adds later.
 */
enum AlertLevel: string
{
    case Critico = 'CRITICO';
    case Alto = 'ALTO';
    case Preventivo = 'PREVENTIVO';
    case Normal = 'NORMAL';
    case Unknown = 'UNKNOWN';

    public static function fromApi(string $value): self
    {
        return self::tryFrom($value) ?? self::Unknown;
    }

    /** Lower is more severe; Unknown sorts last. */
    public function severity(): int
    {
        return match ($this) {
            self::Critico => 0,
            self::Alto => 1,
            self::Preventivo => 2,
            self::Normal => 3,
            self::Unknown => 4,
        };
    }

    /**
     * Classified levels in fixed display order.
     *
     * @return list<self>
     */
    public static function known(): array
    {
        return [self::Critico, self::Alto, self::Preventivo, self::Normal];
    }
}
