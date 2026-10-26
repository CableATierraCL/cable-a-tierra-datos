<?php

declare(strict_types=1);

namespace CableATierra\Datos\Domain;

/**
 * Independently cached NoFires datasets.
 */
enum Dataset: string
{
    case Risk = 'risk';
    case Burned = 'burned';

    /** Transient lifetime in seconds. */
    public function ttl(): int
    {
        return match ($this) {
            self::Risk => 900,
            self::Burned => 86400,
        };
    }
}
