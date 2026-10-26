<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Summary;

use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Port\Clock;
use CableATierra\Datos\Summary\Api;
use CableATierra\Datos\Tests\Support\RepositoryTestCase;

final class ApiTest extends RepositoryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clock->now = gmmktime(15, 0, 0, 10, 25, 2026);
    }

    public function testBuildsASummaryFromRealFixtures(): void
    {
        // Cron warms burned; the page path may make only one inline request, which risk uses.
        $this->http->queue(self::ok(Dataset::Burned, 'nuble'), self::ok(Dataset::Risk, 'nuble'));
        $this->repo->refresh('nuble', Dataset::Burned);

        $s = Api::summary($this->repo, $this->clock, 'nuble');

        $this->assertSame('ok', $s['state']);
        $this->assertSame('nuble', $s['region']);
        $this->assertSame(21, $s['comunaCount']);
        $this->assertTrue($s['burned']['available']);
        $this->assertSame('api', $s['sources']['risk']);
    }

    public function testComunasComeFromTheSameRiskRows(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'nuble'));

        $rows = Api::comunas($this->repo, 'nuble');

        $this->assertCount(21, $rows);
        $this->assertSame(['comunaId', 'nombre', 'alertLevel', 'alertDriver', 'score', 'firmsCount', 'computedAtLocal'], array_keys($rows[0]));
    }

    public function testOutageWithoutLastGoodIsAnUnavailableArray(): void
    {
        $s = Api::summary($this->repo, $this->clock, 'biobio');

        $this->assertSame('unavailable', $s['state']);
        $this->assertSame([], Api::comunas($this->repo, 'biobio'));
    }

    public function testInvalidRegionNeverTouchesTheNetwork(): void
    {
        $s = Api::summary($this->repo, $this->clock, 'atacama');

        $this->assertSame('unavailable', $s['state']);
        $this->assertSame([], Api::comunas($this->repo, 'atacama'));
        $this->assertSame([], $this->http->calls);
    }

    public function testNothingEscapesEvenWhenACollaboratorThrows(): void
    {
        $broken = new class implements Clock {
            public function now(): int
            {
                throw new \RuntimeException('clock exploded');
            }
        };

        $s = Api::summary($this->repo, $broken, 'nuble');

        $this->assertSame('unavailable', $s['state']);
        $this->assertSame('nuble', $s['region']);
    }

    public function testPublicFunctionsAreDefinedAndSafe(): void
    {
        $this->assertSame(1, \cat_datos_api_version());
        $this->assertSame('unavailable', \cat_datos_get_region_summary('atacama')['state']);
        $this->assertSame([], \cat_datos_get_comunas('atacama'));
        // A valid region without WordPress loaded (no transients, no HTTP): still an array, never a throw.
        $this->assertContains(\cat_datos_get_region_summary('nuble')['state'], ['ok', 'stale', 'very_stale', 'unavailable']);
        $this->assertIsArray(\cat_datos_get_comunas('nuble'));
    }
}
