<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Support;

use CableATierra\Datos\Port\Scheduler;

final class FakeScheduler implements Scheduler
{
    /** @var list<string> */
    public array $pending = [];

    public ?\Throwable $throws = null;

    public int $attempts = 0;

    public function scheduleOnce(string $hook): bool
    {
        $this->attempts++;
        if ($this->throws !== null) {
            throw $this->throws;
        }
        if (in_array($hook, $this->pending, true)) {
            return false;
        }
        $this->pending[] = $hook;

        return true;
    }
}
