<?php

declare(strict_types=1);

namespace CableATierra\Datos\Wp;

use CableATierra\Datos\Port\Clock;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
