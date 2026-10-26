<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Support;

use CableATierra\Datos\Data\Repository;
use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Port\HttpResponse;
use PHPUnit\Framework\TestCase;

abstract class RepositoryTestCase extends TestCase
{
    use FixtureLoader;

    protected FakeHttpClient $http;
    protected FakeClock $clock;
    protected FakeCache $cache;
    protected FakeScheduler $scheduler;
    protected Repository $repo;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->clock = new FakeClock();
        $this->cache = new FakeCache($this->clock);
        $this->scheduler = new FakeScheduler();
        $this->repo = new Repository($this->http, $this->clock, $this->cache, $this->scheduler);
    }

    protected static function ok(Dataset $dataset, string $region): HttpResponse
    {
        return new HttpResponse(200, self::fixture("nofires/{$dataset->value}-$region.json"));
    }

    protected static function key(string $kind, Dataset $dataset, string $region): string
    {
        return "cat_datos_v1_{$kind}_{$dataset->value}_$region";
    }

    protected static function dataKey(Dataset $dataset, string $region): string
    {
        return "cat_datos_v1_{$dataset->value}_$region";
    }
}
