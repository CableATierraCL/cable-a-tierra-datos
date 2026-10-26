<?php

declare(strict_types=1);

namespace CableATierra\Datos\Wp;

use CableATierra\Datos\Port\Cache;

/**
 * Transients for expiring data; options (autoload off) for last-good payloads.
 * Only non-false values are ever stored, so WordPress' `false` means "missing".
 */
final class WpCache implements Cache
{
    public function get(string $key): mixed
    {
        $value = get_transient($key);

        return $value === false ? null : $value;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        set_transient($key, $value, $ttl);
    }

    public function delete(string $key): void
    {
        delete_transient($key);
    }

    public function getOption(string $key): mixed
    {
        return get_option($key, null);
    }

    public function setOption(string $key, mixed $value): void
    {
        update_option($key, $value, false);
    }
}
