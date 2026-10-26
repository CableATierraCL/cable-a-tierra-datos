<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Support;

use CableATierra\Datos\Port\Logger;

/** Keeps every message so tests can assert on what was reported. */
final class RecordingLogger implements Logger
{
    /** @var list<string> */
    public array $messages = [];

    public function warning(string $message): void
    {
        $this->messages[] = $message;
    }
}
