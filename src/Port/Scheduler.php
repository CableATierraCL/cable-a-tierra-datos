<?php

declare(strict_types=1);

namespace CableATierra\Datos\Port;

interface Scheduler
{
    /** Queues a one-off run of the hook unless one is already pending. True when newly queued. */
    public function scheduleOnce(string $hook): bool;
}
