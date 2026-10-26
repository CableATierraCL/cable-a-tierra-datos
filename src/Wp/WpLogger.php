<?php

declare(strict_types=1);

namespace CableATierra\Datos\Wp;

use CableATierra\Datos\Port\Logger;

/** Writes to the PHP error log, and only while WP_DEBUG is on (the caller passes that in). */
final class WpLogger implements Logger
{
    public function __construct(private readonly bool $enabled)
    {
    }

    public function warning(string $message): void
    {
        if ($this->enabled) {
            error_log('[cable-a-tierra-datos] ' . $message);
        }
    }
}
