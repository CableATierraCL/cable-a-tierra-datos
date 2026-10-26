<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Wp;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use CableATierra\Datos\Wp\Cron;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class CronTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var list<array{hook: string, schedule: ?string}> In-memory stand-in for the cron table. */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->events = [['hook' => 'cat_datos_refresh', 'schedule' => null]]; // a queued SWR single event
        Functions\when('wp_clear_scheduled_hook')->alias(function (string $hook): int {
            $before = count($this->events);
            $this->events = array_values(array_filter($this->events, static fn (array $e): bool => $e['hook'] !== $hook));

            return $before - count($this->events);
        });
        Functions\when('wp_schedule_event')->alias(function (int $ts, string $schedule, string $hook): bool {
            $this->events[] = ['hook' => $hook, 'schedule' => $schedule];

            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_it_adds_a_fifteen_minute_schedule_and_keeps_the_others(): void
    {
        $schedules = Cron::addSchedule(['hourly' => ['interval' => 3600, 'display' => 'Once Hourly']]);

        $this->assertSame(900, $schedules['cat_datos_15min']['interval']);
        $this->assertNotSame('', $schedules['cat_datos_15min']['display']);
        $this->assertArrayHasKey('hourly', $schedules);
    }

    public function test_the_schedule_interval_matches_the_risk_ttl(): void
    {
        $this->assertSame(\CableATierra\Datos\Domain\Dataset::Risk->ttl(), Cron::INTERVAL);
    }

    public function test_activation_registers_the_schedule_before_scheduling_the_recurring_event(): void
    {
        Filters\expectAdded('cron_schedules')->once();

        Cron::activate();

        $recurring = array_values(array_filter($this->events, static fn (array $e): bool => $e['schedule'] === 'cat_datos_15min'));
        $this->assertCount(1, $recurring);
        $this->assertSame('cat_datos_refresh', $recurring[0]['hook']);
    }

    public function test_activation_is_idempotent_and_replaces_a_pending_single_event(): void
    {
        Cron::activate();
        Cron::activate();
        Cron::activate();

        $this->assertCount(1, $this->events);
        $this->assertSame('cat_datos_15min', $this->events[0]['schedule']);
    }

    public function test_activation_never_throws_when_wordpress_refuses_the_event(): void
    {
        Functions\when('wp_schedule_event')->justReturn(false);

        Cron::activate();

        $this->assertSame([], $this->events);
    }

    public function test_deactivation_unschedules_every_refresh_event_and_keeps_all_data(): void
    {
        $this->events[] = ['hook' => 'cat_datos_refresh', 'schedule' => 'cat_datos_15min'];
        $this->events[] = ['hook' => 'other_hook', 'schedule' => 'hourly'];
        Functions\expect('delete_option')->never();
        Functions\expect('delete_transient')->never();

        Cron::deactivate();

        $this->assertSame([['hook' => 'other_hook', 'schedule' => 'hourly']], $this->events);
    }
}
