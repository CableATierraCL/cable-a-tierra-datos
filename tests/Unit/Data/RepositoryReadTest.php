<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Data;

use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Domain\Source;
use CableATierra\Datos\Port\HttpResponse;
use CableATierra\Datos\Tests\Support\RepositoryTestCase;

final class RepositoryReadTest extends RepositoryTestCase
{
    public function test_fresh_transient_is_served_without_scheduling(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'nuble'));
        $this->repo->refresh('nuble', Dataset::Risk);

        $result = $this->repo->read('nuble', Dataset::Risk);

        $this->assertSame(Source::Cache, $result->source);
        $this->assertSame([], $this->scheduler->pending);
        $this->assertCount(1, $this->http->calls);
    }

    public function test_expired_with_recent_last_good_serves_it_and_schedules_one_refresh(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'nuble'));
        $good = $this->repo->refresh('nuble', Dataset::Risk);
        $this->clock->advance(901);

        $first = $this->repo->read('nuble', Dataset::Risk);
        $this->repo->read('nuble', Dataset::Risk);

        $this->assertSame(Source::LastGood, $first->source);
        $this->assertSame($good->payload, $first->payload);
        $this->assertSame(['cat_datos_refresh'], $this->scheduler->pending);
        $this->assertCount(1, $this->http->calls);
    }

    public function test_last_good_exactly_one_hour_old_is_still_served_without_inline_fetch(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'nuble'));
        $this->repo->refresh('nuble', Dataset::Risk);
        $this->clock->advance(3600);

        $this->assertSame(Source::LastGood, $this->repo->read('nuble', Dataset::Risk)->source);
        $this->assertCount(1, $this->http->calls);
    }

    public function test_last_good_older_than_one_hour_triggers_an_inline_fetch(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'nuble'), self::ok(Dataset::Risk, 'nuble'));
        $this->repo->refresh('nuble', Dataset::Risk);
        $this->clock->advance(3601);

        $this->assertSame(Source::Api, $this->repo->read('nuble', Dataset::Risk)->source);
        $this->assertCount(2, $this->http->calls);
    }

    public function test_stale_last_good_survives_a_failed_inline_fetch(): void
    {
        $this->http->queue(self::ok(Dataset::Burned, 'nuble'), new HttpResponse(502));
        $good = $this->repo->refresh('nuble', Dataset::Burned);
        $this->clock->advance(Dataset::Burned->ttl());

        $result = $this->repo->read('nuble', Dataset::Burned);

        $this->assertSame(Source::LastGood, $result->source);
        $this->assertSame($good->payload, $result->payload);
    }

    public function test_without_last_good_the_read_fetches_inline(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'araucania'));

        $this->assertSame(Source::Api, $this->repo->read('araucania', Dataset::Risk)->source);
        $this->assertSame([], $this->scheduler->pending);
    }

    public function test_without_last_good_and_with_failure_the_read_is_none(): void
    {
        $this->http->queue(new HttpResponse(null, null, 'timeout'));

        $this->assertSame(Source::None, $this->repo->read('araucania', Dataset::Risk)->source);
    }

    public function test_active_backoff_serves_last_good_and_does_not_reschedule(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'nuble'));
        $this->repo->refresh('nuble', Dataset::Risk);
        $this->clock->advance(901);
        $this->cache->set(self::key('backoff', Dataset::Risk, 'nuble'), 1, 300);

        $this->assertSame(Source::LastGood, $this->repo->read('nuble', Dataset::Risk)->source);
        $this->assertSame([], $this->scheduler->pending);
    }

    public function test_invalid_region_is_none_without_side_effects(): void
    {
        $this->assertSame(Source::None, $this->repo->read('atacama', Dataset::Risk)->source);
        $this->assertSame([], $this->http->calls);
        $this->assertSame([], $this->scheduler->pending);
    }
}
