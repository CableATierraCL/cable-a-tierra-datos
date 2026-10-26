<?php

declare(strict_types=1);

namespace CableATierra\Datos\Port;

interface Clock
{
    /** Current Unix timestamp in seconds. */
    public function now(): int;
}
