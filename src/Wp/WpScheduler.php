<?php

declare(strict_types=1);

namespace CableATierra\Datos\Wp;

use CableATierra\Datos\Port\Clock;
use CableATierra\Datos\Port\Scheduler;

/**
 * Queues a single WP-Cron event. Best effort: WP-Cron only fires on traffic (or a system cron
 * hitting wp-cron.php), so a refresh may run late; the repository keeps serving last-good until
 * it does and falls back to one inline fetch only when the data is far past its TTL.
 */
final class WpScheduler implements Scheduler
{
    public function __construct(private readonly Clock $clock)
    {
    }

    public function scheduleOnce(string $hook): bool
    {
        if (wp_next_scheduled($hook) !== false) {
            return false;
        }

        // WordPress may return false or a WP_Error when it refuses the event.
        return wp_schedule_single_event($this->clock->now(), $hook) === true;
    }
}
