<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Wp;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CableATierra\Datos\Data\Repository;
use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Domain\Source;
use CableATierra\Datos\Port\HttpResponse;
use CableATierra\Datos\Tests\Support\FakeClock;
use CableATierra\Datos\Tests\Support\FakeHttpClient;
use CableATierra\Datos\Tests\Support\FakeScheduler;
use CableATierra\Datos\Tests\Support\FixtureLoader;
use CableATierra\Datos\Wp\WpCache;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/** Runs the repository through the real WpCache adapter over WordPress-like storage (`false` on a miss). */
final class RepositoryOverWpCacheTest extends TestCase
{
    use FixtureLoader;
    use MockeryPHPUnitIntegration;

    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var array<string, mixed> */
    private array $options = [];

    private FakeHttpClient $http;
    private FakeClock $clock;
    private FakeScheduler $scheduler;
    private Repository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->transients = [];
        $this->options = [];
        Functions\when('get_transient')->alias(fn (string $k): mixed => $this->transients[$k] ?? false);
        Functions\when('set_transient')->alias(function (string $k, mixed $v): bool {
            $this->transients[$k] = $v;

            return true;
        });
        Functions\when('delete_transient')->alias(function (string $k): bool {
            unset($this->transients[$k]);

            return true;
        });
        Functions\when('get_option')->alias(fn (string $k, mixed $default = false): mixed => $this->options[$k] ?? $default);
        Functions\when('update_option')->alias(function (string $k, mixed $v): bool {
            $this->options[$k] = $v;

            return true;
        });

        $this->http = new FakeHttpClient();
        $this->clock = new FakeClock();
        $this->scheduler = new FakeScheduler();
        $this->repo = new Repository($this->http, $this->clock, new WpCache(), $this->scheduler);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function ok(): HttpResponse
    {
        return new HttpResponse(200, self::fixture('nofires/risk-nuble.json'));
    }

    public function test_miss_fetch_hit_and_last_good_cycle_with_wordpress_false_semantics(): void
    {
        $this->http->queue($this->ok());

        $this->assertSame(Source::Api, $this->repo->read('nuble', Dataset::Risk)->source);
        $this->assertSame(Source::Cache, $this->repo->read('nuble', Dataset::Risk)->source);

        unset($this->transients['cat_datos_v1_risk_nuble']);
        $stale = $this->repo->read('nuble', Dataset::Risk);

        $this->assertSame(Source::LastGood, $stale->source);
        $this->assertSame(['cat_datos_refresh'], $this->scheduler->pending);
        $this->assertCount(1, $this->http->calls);
    }

    public function test_missing_lock_and_backoff_keys_do_not_block_and_set_ones_do(): void
    {
        $this->http->queue(new HttpResponse(502), $this->ok());

        $this->assertSame(Source::None, $this->repo->refresh('nuble', Dataset::Risk)->source);
        $this->assertSame(Source::None, $this->repo->refresh('nuble', Dataset::Risk)->source);
        $this->assertCount(1, $this->http->calls);
        $this->assertArrayNotHasKey('cat_datos_v1_lock_risk_nuble', $this->transients);

        $this->clock->now += 300;
        unset($this->transients['cat_datos_v1_backoff_risk_nuble'], $this->transients['cat_datos_v1_backoff_api']);
        $this->assertSame(Source::Api, $this->repo->refresh('nuble', Dataset::Risk)->source);
    }
}
