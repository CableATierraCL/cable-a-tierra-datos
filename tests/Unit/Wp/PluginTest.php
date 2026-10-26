<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Wp;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use CableATierra\Datos\Wp\Cron;
use CableATierra\Datos\Data\Repository;
use CableATierra\Datos\Port\HttpResponse;
use CableATierra\Datos\Tests\Support\FakeCache;
use CableATierra\Datos\Tests\Support\FakeClock;
use CableATierra\Datos\Tests\Support\FakeHttpClient;
use CableATierra\Datos\Tests\Support\FakeScheduler;
use CableATierra\Datos\Wp\Plugin;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
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

    public function test_boot_hooks_the_refresh_action_without_doing_any_work(): void
    {
        Actions\expectAdded('cat_datos_refresh')->once();

        Plugin::boot();
    }

    public function test_boot_registers_the_cron_schedule_filter(): void
    {
        Filters\expectAdded('cron_schedules')->once()->with([Cron::class, 'addSchedule']);

        Plugin::boot();
    }

    public function test_boot_defers_shortcode_registration_to_init(): void
    {
        Actions\expectAdded('init')->once()->with([Plugin::class, 'registerShortcode']);
        Functions\expect('add_shortcode')->never();

        Plugin::boot();
    }

    public function test_boot_prepares_the_stylesheet_on_wp_enqueue_scripts(): void
    {
        Actions\expectAdded('wp_enqueue_scripts')->once();

        Plugin::boot();
    }

    public function test_register_shortcode_binds_the_tag(): void
    {
        Functions\expect('add_shortcode')->once()->with('cat_datos_home_band', \Mockery::type('callable'));

        Plugin::registerShortcode();
    }

    public function test_lifecycle_hooks_use_the_given_plugin_file(): void
    {
        Functions\expect('register_activation_hook')->once()->with('/plugin/main.php', [Cron::class, 'activate']);
        Functions\expect('register_deactivation_hook')->once()->with('/plugin/main.php', [Cron::class, 'deactivate']);

        Plugin::registerLifecycleHooks('/plugin/main.php');
    }

    public function test_the_plugin_header_version_matches_the_constant(): void
    {
        $header = (string) file_get_contents(CAT_DATOS_FILE);

        $this->assertSame(1, preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $header, $m));
        $this->assertSame(CAT_DATOS_VERSION, $m[1]);
        $this->assertMatchesRegularExpression('/^0\.\d+\.\d+$/', CAT_DATOS_VERSION, 'stays 0.x until stage 1 is verified in production');
    }

    public function test_refresh_all_covers_three_regions_and_both_datasets_in_display_order(): void
    {
        $http = (new FakeHttpClient())->queue(
            new HttpResponse(200, '{"success":false}'),
            new HttpResponse(200, '{"success":false}'),
            new HttpResponse(200, '{"success":false}'),
            new HttpResponse(200, '{"success":false}'),
            new HttpResponse(200, '{"success":false}'),
            new HttpResponse(200, '{"success":false}'),
        );
        $clock = new FakeClock();
        $repo = new Repository($http, $clock, new FakeCache($clock), new FakeScheduler());

        Plugin::refreshAll($repo);

        $this->assertCount(6, $http->calls);
        $this->assertStringEndsWith('/risk-score/comunas/nuble', $http->calls[0]);
        $this->assertStringEndsWith('regionId=nuble&indicators=BURNED', $http->calls[1]);
        $this->assertStringEndsWith('/risk-score/comunas/araucania', $http->calls[4]);
    }

    public function test_refresh_all_only_fetches_what_is_due(): void
    {
        $http = (new FakeHttpClient())->queue(...array_fill(0, 6, new HttpResponse(200, '{"success":false}')));
        $clock = new FakeClock();
        $repo = new Repository($http, $clock, new FakeCache($clock), new FakeScheduler());
        Plugin::refreshAll($repo);

        Plugin::refreshAll($repo);

        $this->assertCount(6, $http->calls, 'backoff must suppress an immediate second round');
    }
}
