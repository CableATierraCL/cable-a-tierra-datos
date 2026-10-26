<?php

declare(strict_types=1);

namespace CableATierra\Datos\Port;

/**
 * Expiring entries (transients) and persistent entries (options, never autoloaded).
 * Reads return null when the entry is missing or expired.
 */
interface Cache
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttl): void;

    public function delete(string $key): void;

    public function getOption(string $key): mixed;

    public function setOption(string $key, mixed $value): void;
}
