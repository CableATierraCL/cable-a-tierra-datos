<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Data;

use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Domain\Region;
use CableATierra\Datos\Domain\Source;
use CableATierra\Datos\Port\HttpResponse;
use CableATierra\Datos\Tests\Support\RepositoryTestCase;
use CableATierra\Datos\Wp\Plugin;

/** Request budget: the page path must rarely block, and an outage must not multiply timeouts. */
final class RepositoryBudgetTest extends RepositoryTestCase
{
    private const GLOBAL_BACKOFF = 'cat_datos_v1_backoff_api';

    /** @return list<array{Region, Dataset}> */
    private static function pool(): array
    {
        $pairs = [];
        foreach (Region::cases() as $region) {
            foreach (Dataset::cases() as $dataset) {
                $pairs[] = [$region, $dataset];
            }
        }

        return $pairs;
    }

    private function seedLastGood(string $region, Dataset $dataset, int $fetchedAt): void
    {
        $this->http->queue(self::ok($dataset, $region));
        $this->repo->refresh($region, $dataset);
        $entry = $this->cache->options[self::key('lastgood', $dataset, $region)];
        $entry['fetchedAt'] = $fetchedAt;
        $this->cache->options[self::key('lastgood', $dataset, $region)] = $entry;
        $this->cache->transients = [];
        $this->http->calls = [];
        $this->http->timeouts = [];
    }

    // --- one inline fetch per request, global backoff -------------------------------------

    public function test_pool_with_api_down_makes_at_most_one_http_call_per_page_request(): void
    {
        foreach (self::pool() as [$region, $dataset]) {
            $this->assertSame(Source::None, $this->repo->read($region->value, $dataset)->source);
        }

        $this->assertCount(1, $this->http->calls);
    }

    public function test_pool_makes_no_http_call_while_the_global_backoff_is_active(): void
    {
        $this->http->queue(new HttpResponse(null, null, 'timeout'));
        $this->repo->read('nuble', Dataset::Risk);
        $this->assertCount(1, $this->http->calls);

        $this->clock->advance(299);
        $fresh = new \CableATierra\Datos\Data\Repository($this->http, $this->clock, $this->cache, $this->scheduler);
        foreach (self::pool() as [$region, $dataset]) {
            $fresh->read($region->value, $dataset);
            $fresh->refresh($region->value, $dataset);
        }

        $this->assertCount(1, $this->http->calls);
    }

    public function test_only_one_pair_per_request_fetches_inline_even_when_the_first_succeeds(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'nuble'), self::ok(Dataset::Risk, 'biobio'));

        $first = $this->repo->read('nuble', Dataset::Risk);
        $second = $this->repo->read('biobio', Dataset::Risk);

        $this->assertSame(Source::Api, $first->source);
        $this->assertSame(Source::None, $second->source);
        $this->assertCount(1, $this->http->calls);
        $this->assertSame(['cat_datos_refresh'], $this->scheduler->pending);
    }

    public function test_other_pairs_serve_last_good_and_schedule_cron_after_the_inline_fetch(): void
    {
        $this->seedLastGood('biobio', Dataset::Risk, $this->clock->now() - 900 - 21601);
        $this->http->queue(self::ok(Dataset::Risk, 'nuble'));

        $this->repo->read('nuble', Dataset::Risk);
        $other = $this->repo->read('biobio', Dataset::Risk);

        $this->assertSame(Source::LastGood, $other->source);
        $this->assertCount(1, $this->http->calls);
        $this->assertSame(['cat_datos_refresh'], $this->scheduler->pending);
    }

    public function test_a_new_request_gets_a_new_inline_budget(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'nuble'), self::ok(Dataset::Risk, 'biobio'));
        $this->repo->read('nuble', Dataset::Risk);

        $nextRequest = new \CableATierra\Datos\Data\Repository($this->http, $this->clock, $this->cache, $this->scheduler);

        $this->assertSame(Source::Api, $nextRequest->read('biobio', Dataset::Risk)->source);
    }

    public function test_cron_with_api_down_stops_after_the_first_transport_failure(): void
    {
        Plugin::refreshAll($this->repo);

        $this->assertCount(1, $this->http->calls);
    }

    public function test_cron_stops_when_the_http_client_throws(): void
    {
        $this->http->queue(new \RuntimeException('boom'));

        Plugin::refreshAll($this->repo);

        $this->assertCount(1, $this->http->calls);
        $this->assertArrayHasKey(self::GLOBAL_BACKOFF, $this->cache->transients);
    }

    public function test_cron_continues_when_a_pair_fails_only_on_normalization(): void
    {
        $this->http->queue(...array_fill(0, 6, new HttpResponse(200, '{"success":false}')));

        Plugin::refreshAll($this->repo);

        $this->assertCount(6, $this->http->calls);
        $this->assertArrayNotHasKey(self::GLOBAL_BACKOFF, $this->cache->transients);
    }

    /** @return array<string, array{HttpResponse|\Throwable}> */
    public static function transportFailures(): array
    {
        return [
            'wp_error' => [new HttpResponse(null, null, 'cURL error 28')],
            'timeout' => [new HttpResponse(null, null, 'timeout')],
            '404' => [new HttpResponse(404, 'nope')],
            '500' => [new HttpResponse(500, '')],
            '502' => [new HttpResponse(502, '<html>')],
            'throws' => [new \RuntimeException('boom')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('transportFailures')]
    public function test_transport_level_failures_set_the_global_backoff_for_300_seconds(HttpResponse|\Throwable $failure): void
    {
        $this->http->queue($failure);

        $this->repo->refresh('nuble', Dataset::Risk);

        $this->assertContains('transient:' . self::GLOBAL_BACKOFF . ':300', $this->cache->writes);
    }

    public function test_a_bad_200_body_does_not_set_the_global_backoff(): void
    {
        $this->http->queue(new HttpResponse(200, '<html>'));

        $this->repo->refresh('nuble', Dataset::Risk);

        $this->assertNotContains('transient:' . self::GLOBAL_BACKOFF . ':300', $this->cache->writes);
        $this->assertArrayHasKey(self::key('backoff', Dataset::Risk, 'nuble'), $this->cache->transients);
    }

    public function test_global_backoff_blocks_refresh_returns_last_good_and_expires_after_300_seconds(): void
    {
        $this->seedLastGood('nuble', Dataset::Risk, $this->clock->now() - 5000);
        $this->cache->set(self::GLOBAL_BACKOFF, 1, 300);

        $blocked = $this->repo->refresh('biobio', Dataset::Risk);
        $lastGood = $this->repo->refresh('nuble', Dataset::Risk);

        $this->assertSame(Source::None, $blocked->source);
        $this->assertSame(Source::LastGood, $lastGood->source);
        $this->assertSame([], $this->http->calls);

        $this->clock->advance(300);
        $this->http->queue(self::ok(Dataset::Risk, 'biobio'));
        $this->assertSame(Source::Api, $this->repo->refresh('biobio', Dataset::Risk)->source);
    }

    public function test_global_backoff_suppresses_cron_scheduling(): void
    {
        $this->cache->set(self::GLOBAL_BACKOFF, 1, 300);

        $this->repo->read('nuble', Dataset::Risk);

        $this->assertSame([], $this->scheduler->pending);
    }

    // --- timeouts -------------------------------------------------------------------------

    public function test_inline_fetch_uses_a_three_second_timeout_and_cron_five(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'nuble'), self::ok(Dataset::Risk, 'biobio'));

        $this->repo->read('nuble', Dataset::Risk);
        $this->repo->refresh('biobio', Dataset::Risk);

        $this->assertSame([3.0, 5.0], $this->http->timeouts);
    }

    // --- stale-while-revalidate through cron ----------------------------------------------

    public function test_burned_just_past_its_ttl_serves_last_good_and_schedules_cron(): void
    {
        $this->seedLastGood('nuble', Dataset::Burned, $this->clock->now() - Dataset::Burned->ttl() - 1);

        $result = $this->repo->read('nuble', Dataset::Burned);

        $this->assertSame(Source::LastGood, $result->source);
        $this->assertSame(['cat_datos_refresh'], $this->scheduler->pending);
        $this->assertSame([], $this->http->calls);
    }

    public function test_last_good_exactly_ttl_plus_six_hours_old_is_still_served_via_cron(): void
    {
        $this->seedLastGood('nuble', Dataset::Risk, $this->clock->now() - 900 - 21600);

        $this->assertSame(Source::LastGood, $this->repo->read('nuble', Dataset::Risk)->source);
        $this->assertSame([], $this->http->calls);
    }

    public function test_last_good_more_than_ttl_plus_six_hours_old_triggers_one_inline_fetch(): void
    {
        $this->seedLastGood('nuble', Dataset::Burned, $this->clock->now() - Dataset::Burned->ttl() - 21601);
        $this->http->queue(self::ok(Dataset::Burned, 'nuble'));

        $this->assertSame(Source::Api, $this->repo->read('nuble', Dataset::Burned)->source);
        $this->assertCount(1, $this->http->calls);
    }

    public function test_a_very_old_last_good_survives_a_failed_inline_fetch(): void
    {
        $this->seedLastGood('nuble', Dataset::Risk, $this->clock->now() - 30 * 86400);
        $this->http->queue(new HttpResponse(502));

        $this->assertSame(Source::LastGood, $this->repo->read('nuble', Dataset::Risk)->source);
    }

    public function test_a_very_old_last_good_is_not_refetched_while_the_pair_is_in_backoff(): void
    {
        $this->seedLastGood('nuble', Dataset::Risk, $this->clock->now() - 30 * 86400);
        $this->cache->set(self::key('backoff', Dataset::Risk, 'nuble'), 1, 300);

        $this->assertSame(Source::LastGood, $this->repo->read('nuble', Dataset::Risk)->source);
        $this->assertSame([], $this->http->calls);
    }

    // --- resilience -----------------------------------------------------------------------

    public function test_a_throwing_scheduler_does_not_drop_the_last_good(): void
    {
        $this->seedLastGood('nuble', Dataset::Risk, $this->clock->now() - 1000);
        $this->scheduler->throws = new \RuntimeException('cron table locked');

        $result = $this->repo->read('nuble', Dataset::Risk);

        $this->assertSame(Source::LastGood, $result->source);
        $this->assertSame(1, $this->scheduler->attempts);
    }

    public function test_a_last_good_dated_in_the_future_is_servable_and_schedules_a_refresh(): void
    {
        $this->seedLastGood('nuble', Dataset::Risk, $this->clock->now() + 7200);

        $result = $this->repo->read('nuble', Dataset::Risk);

        $this->assertSame(Source::LastGood, $result->source);
        $this->assertSame(['cat_datos_refresh'], $this->scheduler->pending);
        $this->assertSame([], $this->http->calls);
    }
}
