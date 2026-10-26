<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Wp;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CableATierra\Datos\Tests\Support\FakeClock;
use CableATierra\Datos\Tests\Support\StubWpError;
use CableATierra\Datos\Wp\SystemClock;
use CableATierra\Datos\Wp\WpCache;
use CableATierra\Datos\Wp\WpScheduler;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class WpCacheSchedulerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_transients_map_to_wp_functions_and_miss_is_null(): void
    {
        Functions\expect('get_transient')->once()->with('k')->andReturn(false);
        Functions\expect('get_transient')->once()->with('hit')->andReturn(['a' => 1]);
        Functions\expect('set_transient')->once()->with('k', 'v', 900);
        Functions\expect('delete_transient')->once()->with('k');
        $cache = new WpCache();

        $this->assertNull($cache->get('k'));
        $this->assertSame(['a' => 1], $cache->get('hit'));
        $cache->set('k', 'v', 900);
        $cache->delete('k');
    }

    public function test_options_are_written_without_autoload_and_read_with_null_default(): void
    {
        Functions\expect('update_option')->once()->with('opt', ['x'], false);
        Functions\expect('get_option')->once()->with('opt', null)->andReturn(['x']);
        $cache = new WpCache();

        $cache->setOption('opt', ['x']);
        $this->assertSame(['x'], $cache->getOption('opt'));
    }

    public function test_system_clock_reads_real_time(): void
    {
        $before = time();
        $now = (new SystemClock())->now();

        $this->assertGreaterThanOrEqual($before, $now);
        $this->assertLessThanOrEqual(time(), $now);
    }

    public function test_scheduler_queues_a_single_event_when_none_is_pending(): void
    {
        $clock = new FakeClock(1234);
        Functions\expect('wp_next_scheduled')->once()->with('cat_datos_refresh')->andReturn(false);
        Functions\expect('wp_schedule_single_event')->once()->with(1234, 'cat_datos_refresh')->andReturn(true);

        $this->assertTrue((new WpScheduler($clock))->scheduleOnce('cat_datos_refresh'));
    }

    public function test_scheduler_does_not_queue_a_duplicate(): void
    {
        Functions\expect('wp_next_scheduled')->once()->andReturn(1700000000);
        Functions\expect('wp_schedule_single_event')->never();

        $this->assertFalse((new WpScheduler(new FakeClock()))->scheduleOnce('cat_datos_refresh'));
    }

    public function test_scheduler_reports_false_when_wordpress_refuses(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->justReturn(new StubWpError());

        $this->assertFalse((new WpScheduler(new FakeClock()))->scheduleOnce('cat_datos_refresh'));
    }
}
