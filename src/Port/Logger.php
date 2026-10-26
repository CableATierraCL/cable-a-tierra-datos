<?php

declare(strict_types=1);

namespace CableATierra\Datos\Port;

/**
 * Sink for operational warnings. Messages never contain response bodies or secrets.
 */
interface Logger
{
    public function warning(string $message): void;
}
