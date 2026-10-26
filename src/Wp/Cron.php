<?php

declare(strict_types=1);

namespace CableATierra\Datos\Wp;

use CableATierra\Datos\Data\Repository;

/**
 * Recurring refresh schedule. One event every 15 minutes (the risk TTL): about 288 risk requests
 * a day plus 3 burned ones, matching the request budget. WP-Cron reschedules after each run, so
 * a run always starts after the previous transient expired and never skips a cycle.
 */
final class Cron
{
    public const SCHEDULE = 'cat_datos_15min';
    public const INTERVAL = 900;

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    public static function addSchedule(array $schedules): array
    {
        $schedules[self::SCHEDULE] = ['interval' => self::INTERVAL, 'display' => 'Every 15 minutes (Cable a Tierra Datos)'];

        return $schedules;
    }

    /**
     * Idempotent. Clearing first also drops a queued single refresh event (same hook), which
     * would otherwise make a "next scheduled" check skip the recurring one. The plugin has not
     * booted yet during activation, so the schedule is registered here too.
     */
    public static function activate(): void
    {
        add_filter('cron_schedules', [self::class, 'addSchedule']);
        wp_clear_scheduled_hook(Repository::REFRESH_HOOK);
        wp_schedule_event(time(), self::SCHEDULE, Repository::REFRESH_HOOK);
    }

    /** Stops refreshing; stored data is kept so a re-activation resumes with last-good payloads. */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(Repository::REFRESH_HOOK);
    }
}
