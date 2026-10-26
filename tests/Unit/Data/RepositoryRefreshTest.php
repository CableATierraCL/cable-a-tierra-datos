<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Data;

use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Domain\Source;
use CableATierra\Datos\Port\HttpResponse;
use CableATierra\Datos\Tests\Support\RepositoryTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RepositoryRefreshTest extends RepositoryTestCase
{
    private const BASE = 'https://nofires-backend-production.up.railway.app';

    public function test_miss_then_success_stores_transient_and_last_good(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'biobio'));

        $result = $this->repo->refresh('biobio', Dataset::Risk);

        $this->assertSame(Source::Api, $result->source);
        $this->assertCount(33, $result->payload);
        $this->assertSame([self::BASE . '/api/territory/risk-score/comunas/biobio'], $this->http->calls);
        $this->assertContains('transient:cat_datos_v1_risk_biobio:900', $this->cache->writes);
        $lastGood = $this->cache->options['cat_datos_v1_lastgood_risk_biobio'];
        $this->assertSame($this->clock->now(), $lastGood['fetchedAt']);
        $this->assertSame($result->payload, $lastGood['payload']);
        $this->assertSame($this->clock->now(), $result->fetchedAt);
    }

    public function test_burned_uses_daily_ttl_and_layers_url(): void
    {
        $this->http->queue(self::ok(Dataset::Burned, 'nuble'));

        $result = $this->repo->refresh('nuble', Dataset::Burned);

        $this->assertSame(Source::Api, $result->source);
        $this->assertSame([self::BASE . '/api/territory/public/layers?regionId=nuble&indicators=BURNED'], $this->http->calls);
        $this->assertContains('transient:cat_datos_v1_burned_nuble:86400', $this->cache->writes);
        $this->assertArrayHasKey('cat_datos_v1_lastgood_burned_nuble', $this->cache->options);
    }

    public function test_fresh_transient_is_served_without_http(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'biobio'));
        $first = $this->repo->refresh('biobio', Dataset::Risk);
        $this->clock->advance(300);

        $second = $this->repo->refresh('biobio', Dataset::Risk);

        $this->assertSame(Source::Cache, $second->source);
        $this->assertSame($first->payload, $second->payload);
        $this->assertCount(1, $this->http->calls);
    }

    public function test_a_pair_becomes_due_for_cron_120_seconds_before_its_ttl(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'biobio'), self::ok(Dataset::Risk, 'biobio'));
        $this->repo->refresh('biobio', Dataset::Risk);

        $this->clock->advance(779);
        $this->repo->refresh('biobio', Dataset::Risk);
        $this->assertCount(1, $this->http->calls);

        $this->clock->advance(1);
        $this->assertSame(Source::Api, $this->repo->refresh('biobio', Dataset::Risk)->source);
        $this->assertCount(2, $this->http->calls);
    }

    public function test_a_cron_run_one_grid_step_after_a_late_write_still_fetches(): void
    {
        // The transient was written a few seconds after the grid tick (lag + fetch time).
        $this->http->queue(self::ok(Dataset::Risk, 'biobio'), self::ok(Dataset::Risk, 'biobio'));
        $this->clock->advance(3);
        $this->repo->refresh('biobio', Dataset::Risk);

        $this->clock->advance(897); // next tick on the grid: 900 s after the previous tick, 897 s after the write
        $this->assertGreaterThan(0, count($this->cache->transients));
        $this->assertSame(Source::Api, $this->repo->refresh('biobio', Dataset::Risk)->source);
        $this->assertCount(2, $this->http->calls);
    }

    public function test_a_cron_run_well_before_the_ttl_does_not_fetch(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'biobio'), self::ok(Dataset::Risk, 'biobio'));
        $this->repo->refresh('biobio', Dataset::Risk);

        $this->clock->advance(600);

        $this->assertSame(Source::Cache, $this->repo->refresh('biobio', Dataset::Risk)->source);
        $this->assertCount(1, $this->http->calls);
    }

    public function test_burned_is_not_refetched_by_every_fifteen_minute_run(): void
    {
        $this->http->queue(self::ok(Dataset::Burned, 'nuble'), self::ok(Dataset::Burned, 'nuble'));
        $this->repo->refresh('nuble', Dataset::Burned);

        for ($i = 0; $i < 90; $i++) { // 22.5 hours of 15-minute runs
            $this->clock->advance(900);
            $this->repo->refresh('nuble', Dataset::Burned);
        }
        $this->assertCount(1, $this->http->calls);

        $this->clock->advance(86400 - 120 - 90 * 900); // reach ttl - 120
        $this->assertSame(Source::Api, $this->repo->refresh('nuble', Dataset::Burned)->source);
        $this->assertCount(2, $this->http->calls);
    }

    public function test_an_early_refresh_failure_keeps_serving_the_still_fresh_transient_data(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'biobio'), new HttpResponse(500));
        $good = $this->repo->refresh('biobio', Dataset::Risk);
        $this->clock->advance(800);

        $result = $this->repo->refresh('biobio', Dataset::Risk);

        $this->assertSame($good->payload, $result->payload);
        $this->assertSame(Source::LastGood, $result->source);
    }

    public function test_the_due_check_does_not_bypass_backoff_or_lock(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'biobio'), new HttpResponse(500));
        $this->repo->refresh('biobio', Dataset::Risk);
        $this->clock->advance(800);
        $this->cache->set(self::key('lock', Dataset::Risk, 'biobio'), 1, 30);

        $this->repo->refresh('biobio', Dataset::Risk);

        $this->assertCount(1, $this->http->calls);
    }

    public function test_the_page_path_still_serves_the_fresh_transient_when_the_pair_is_cron_due(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'biobio'));
        $this->repo->refresh('biobio', Dataset::Risk);
        $this->clock->advance(800);

        $this->assertSame(Source::Cache, $this->repo->read('biobio', Dataset::Risk)->source);
        $this->assertCount(1, $this->http->calls);
    }

    public function test_transport_error_falls_back_to_untouched_last_good(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'biobio'), new HttpResponse(null, null, 'timeout'));
        $good = $this->repo->refresh('biobio', Dataset::Risk);
        $this->clock->advance(30 * 3600);
        $optionsBefore = $this->cache->options;
        $writesBefore = count($this->cache->writes);

        $result = $this->repo->refresh('biobio', Dataset::Risk);

        $this->assertSame(Source::LastGood, $result->source);
        $this->assertSame($good->payload, $result->payload);
        $this->assertSame($good->fetchedAt, $result->fetchedAt);
        $this->assertSame($optionsBefore, $this->cache->options);
        $this->assertNull($this->cache->get(self::dataKey(Dataset::Risk, 'biobio')));
        $this->assertSame(
            [
                'transient:cat_datos_v1_lock_risk_biobio:30',
                'transient:cat_datos_v1_backoff_api:300',
                'transient:cat_datos_v1_backoff_risk_biobio:300',
            ],
            array_slice($this->cache->writes, $writesBefore),
        );
    }

    public function test_failure_without_last_good_is_none_without_exception(): void
    {
        $this->http->queue(new HttpResponse(null, null, 'dns'));

        $result = $this->repo->refresh('biobio', Dataset::Risk);

        $this->assertSame(Source::None, $result->source);
        $this->assertNull($result->payload);
        $this->assertSame([], $this->cache->options);
    }

    /** @return array<string, array{int}> */
    public static function badStatuses(): array
    {
        return ['403' => [403], '404' => [404], '500' => [500], '502' => [502], '204' => [204], '301' => [301]];
    }

    #[DataProvider('badStatuses')]
    public function test_non_200_is_a_failure_even_with_a_valid_body(int $status): void
    {
        $this->http->queue(new HttpResponse($status, self::ok(Dataset::Risk, 'biobio')->body));

        $this->assertSame(Source::None, $this->repo->refresh('biobio', Dataset::Risk)->source);
        $this->assertSame([], $this->cache->options);
    }

    /** @return array<string, array{?string, string}> */
    public static function badBodies(): array
    {
        return [
            'html' => ['<html>502</html>', 'risk'],
            'empty' => ['', 'risk'],
            'null body' => [null, 'risk'],
            'truncated' => ['{', 'risk'],
            'burned success false' => ['{"success":false,"message":"x"}', 'burned'],
        ];
    }

    #[DataProvider('badBodies')]
    public function test_invalid_200_body_keeps_last_good(?string $body, string $dataset): void
    {
        $dataset = Dataset::from($dataset);
        $this->http->queue(self::ok($dataset, 'nuble'), new HttpResponse(200, $body));
        $good = $this->repo->refresh('nuble', $dataset);
        $this->clock->advance($dataset->ttl());

        $result = $this->repo->refresh('nuble', $dataset);

        $this->assertSame(Source::LastGood, $result->source);
        $this->assertSame($good->payload, $result->payload);
        $this->assertSame($good->payload, $this->cache->options[self::key('lastgood', $dataset, 'nuble')]['payload']);
    }

    public function test_regions_are_isolated(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'araucania'), new HttpResponse(200, '<html>'), self::ok(Dataset::Risk, 'nuble'));
        $this->repo->refresh('araucania', Dataset::Risk);
        $araucania = $this->cache->transients;

        $biobio = $this->repo->refresh('biobio', Dataset::Risk);
        $nuble = $this->repo->refresh('nuble', Dataset::Risk);

        $this->assertSame(Source::None, $biobio->source);
        $this->assertSame(Source::Api, $nuble->source);
        $this->assertSame(
            $araucania[self::dataKey(Dataset::Risk, 'araucania')],
            $this->cache->transients[self::dataKey(Dataset::Risk, 'araucania')],
        );
    }

    public function test_datasets_are_isolated(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'nuble'), new HttpResponse(200, '{"success":false}'));

        $this->assertSame(Source::Api, $this->repo->refresh('nuble', Dataset::Risk)->source);
        $this->assertSame(Source::None, $this->repo->refresh('nuble', Dataset::Burned)->source);
        $this->assertArrayHasKey(self::dataKey(Dataset::Risk, 'nuble'), $this->cache->transients);
    }

    /** @return array<string, array{string}> */
    public static function invalidRegions(): array
    {
        return ['unknown' => ['atacama'], 'empty' => [''], 'case' => ['BIOBIO'], 'traversal' => ['../x'], 'query' => ['nuble&x=1']];
    }

    #[DataProvider('invalidRegions')]
    public function test_invalid_region_is_rejected_without_http_or_cache_writes(string $region): void
    {
        $result = $this->repo->refresh($region, Dataset::Risk);

        $this->assertSame(Source::None, $result->source);
        $this->assertSame([], $this->http->calls);
        $this->assertSame([], $this->cache->writes);
    }

    public function test_failure_sets_a_backoff_that_suppresses_retries_for_300_seconds(): void
    {
        $this->http->queue(new HttpResponse(502), new HttpResponse(502), self::ok(Dataset::Risk, 'nuble'));
        $this->repo->refresh('nuble', Dataset::Risk);

        $this->clock->advance(299);
        $this->assertSame(Source::None, $this->repo->refresh('nuble', Dataset::Risk)->source);
        $this->assertCount(1, $this->http->calls);
        $this->assertContains('transient:cat_datos_v1_backoff_risk_nuble:300', $this->cache->writes);

        $this->clock->advance(1);
        $this->repo->refresh('nuble', Dataset::Risk);
        $this->assertCount(2, $this->http->calls);
        $this->repo->refresh('nuble', Dataset::Risk);
        $this->assertCount(2, $this->http->calls);
    }

    public function test_success_clears_backoff_state_and_releases_lock(): void
    {
        $this->http->queue(self::ok(Dataset::Risk, 'nuble'));

        $this->repo->refresh('nuble', Dataset::Risk);

        $this->assertContains('transient:cat_datos_v1_lock_risk_nuble:30', $this->cache->writes);
        $this->assertArrayNotHasKey(self::key('lock', Dataset::Risk, 'nuble'), $this->cache->transients);
        $this->assertArrayNotHasKey(self::key('backoff', Dataset::Risk, 'nuble'), $this->cache->transients);
    }

    public function test_held_lock_prevents_a_concurrent_fetch(): void
    {
        $this->cache->set(self::key('lock', Dataset::Risk, 'nuble'), 1, 30);

        $result = $this->repo->refresh('nuble', Dataset::Risk);

        $this->assertSame(Source::None, $result->source);
        $this->assertSame([], $this->http->calls);
    }

    public function test_lock_expires_after_30_seconds(): void
    {
        $this->cache->set(self::key('lock', Dataset::Risk, 'nuble'), 1, 30);
        $this->http->queue(self::ok(Dataset::Risk, 'nuble'));
        $this->clock->advance(30);

        $this->assertSame(Source::Api, $this->repo->refresh('nuble', Dataset::Risk)->source);
    }

    public function test_lock_is_released_when_the_fetch_fails(): void
    {
        $this->http->queue(new HttpResponse(500));

        $this->repo->refresh('nuble', Dataset::Risk);

        $this->assertArrayNotHasKey(self::key('lock', Dataset::Risk, 'nuble'), $this->cache->transients);
    }

    /** @return array<string, array{mixed}> */
    public static function garbage(): array
    {
        $row = ['comunaId' => 'CHL.1', 'nombreComuna' => 'X', 'alertLevel' => 'ALTO', 'computedAt' => '2026-10-25T10:00:00Z'];

        return [
            'false' => [false],
            'string' => ['junk'],
            'int' => [42],
            'empty array' => [[]],
            'no version' => [['payload' => [$row], 'fetchedAt' => 1]],
            'older schema' => [['v' => 0, 'payload' => [$row], 'fetchedAt' => 1]],
            'newer schema' => [['v' => 2, 'payload' => [$row], 'fetchedAt' => 1]],
            'no payload' => [['v' => 1, 'fetchedAt' => 1]],
            'scalar payload' => [['v' => 1, 'payload' => 'x', 'fetchedAt' => 1]],
            'empty payload' => [['v' => 1, 'payload' => [], 'fetchedAt' => 1]],
            'malformed rows' => [['v' => 1, 'payload' => [['x' => 1]], 'fetchedAt' => 1]],
            'string fetchedAt' => [['v' => 1, 'payload' => [$row], 'fetchedAt' => 'yesterday']],
            'no fetchedAt' => [['v' => 1, 'payload' => [$row]]],
        ];
    }

    #[DataProvider('garbage')]
    public function test_corrupted_transient_is_treated_as_a_miss_and_replaced(mixed $value): void
    {
        $this->cache->set(self::dataKey(Dataset::Risk, 'nuble'), $value, 900);
        $this->http->queue(self::ok(Dataset::Risk, 'nuble'));

        $result = $this->repo->refresh('nuble', Dataset::Risk);

        $this->assertSame(Source::Api, $result->source);
        $this->assertCount(21, $this->cache->transients[self::dataKey(Dataset::Risk, 'nuble')][0]['payload']);
    }

    #[DataProvider('garbage')]
    public function test_corrupted_last_good_is_absent_and_a_good_fetch_replaces_it(mixed $value): void
    {
        $key = self::key('lastgood', Dataset::Risk, 'nuble');
        $this->cache->options[$key] = $value;
        $this->http->queue(new HttpResponse(500), self::ok(Dataset::Risk, 'nuble'));

        $this->assertSame(Source::None, $this->repo->refresh('nuble', Dataset::Risk)->source);
        $this->assertSame($value, $this->cache->options[$key]);

        $this->clock->advance(300);
        $this->assertSame(Source::Api, $this->repo->refresh('nuble', Dataset::Risk)->source);
        $this->assertSame(1, $this->cache->options[$key]['v']);
    }

    public function test_malformed_burned_payload_in_cache_is_rejected(): void
    {
        $this->cache->set(self::dataKey(Dataset::Burned, 'nuble'), ['v' => 1, 'fetchedAt' => 1, 'payload' => ['year' => 2025]], 900);
        $this->http->queue(new HttpResponse(500));

        $this->assertSame(Source::None, $this->repo->refresh('nuble', Dataset::Burned)->source);
    }

    public function test_exceptions_from_collaborators_never_escape(): void
    {
        $this->http->queue(new \RuntimeException('boom'));
        $this->assertSame(Source::None, $this->repo->refresh('nuble', Dataset::Risk)->source);
        $this->assertArrayNotHasKey(self::key('lock', Dataset::Risk, 'nuble'), $this->cache->transients);

        $this->cache->broken = true;
        $this->assertSame(Source::None, $this->repo->refresh('nuble', Dataset::Risk)->source);
        $this->assertSame(Source::None, $this->repo->read('nuble', Dataset::Risk)->source);
    }
}
