<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Support;

use CableATierra\Datos\Port\Scheduler;

final class FakeScheduler implements Scheduler
{
    /** @var list<string> */
    public array $pending = [];

    public function scheduleOnce(string $hook): bool
    {
        if (in_array($hook, $this->pending, true)) {
            return false;
        }
        $this->pending[] = $hook;

        return true;
    }
}
