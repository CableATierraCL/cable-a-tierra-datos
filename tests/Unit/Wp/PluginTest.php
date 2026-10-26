<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Wp;

use Brain\Monkey;
use Brain\Monkey\Actions;
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
