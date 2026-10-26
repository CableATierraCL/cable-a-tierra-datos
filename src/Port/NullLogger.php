<?php

declare(strict_types=1);

namespace CableATierra\Datos\Port;

/** Default logger: discards everything. */
final class NullLogger implements Logger
{
    public function warning(string $message): void
    {
    }
}
