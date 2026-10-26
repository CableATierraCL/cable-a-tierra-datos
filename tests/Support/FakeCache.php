<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Support;

use CableATierra\Datos\Port\Cache;

/** Transient store with TTL expiry driven by the fake clock, plus a plain option store. */
final class FakeCache implements Cache
{
    /** @var array<string, array{mixed, int}> */
    public array $transients = [];

    /** @var array<string, mixed> */
    public array $options = [];

    /** @var list<string> */
    public array $writes = [];

    public bool $broken = false;

    public function __construct(private readonly FakeClock $clock)
    {
    }

    public function get(string $key): mixed
    {
        $this->guard();
        $entry = $this->transients[$key] ?? null;

        // Expired exactly at the TTL boundary.
        return $entry !== null && $this->clock->now() < $entry[1] ? $entry[0] : null;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $this->guard();
        $this->writes[] = "transient:$key:$ttl";
        $this->transients[$key] = [$value, $this->clock->now() + $ttl];
    }

    public function delete(string $key): void
    {
        $this->guard();
        unset($this->transients[$key]);
    }

    public function getOption(string $key): mixed
    {
        $this->guard();

        return $this->options[$key] ?? null;
    }

    public function setOption(string $key, mixed $value): void
    {
        $this->guard();
        $this->writes[] = "option:$key";
        $this->options[$key] = $value;
    }

    private function guard(): void
    {
        if ($this->broken) {
            throw new \RuntimeException('cache backend down');
        }
    }
}
