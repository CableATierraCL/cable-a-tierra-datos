<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Data;

use CableATierra\Datos\Data\Repository;
use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Tests\Support\RepositoryTestCase;

/** The uninstaller deletes exactly what the repository can write. */
final class RepositoryKeysTest extends RepositoryTestCase
{
    public function test_transient_keys_cover_data_lock_and_backoff_for_every_pair_plus_the_api_guard(): void
    {
        $keys = Repository::transientKeys();

        foreach (['nuble', 'biobio', 'araucania'] as $region) {
            foreach (Dataset::cases() as $dataset) {
                $this->assertContains(self::dataKey($dataset, $region), $keys);
                $this->assertContains(self::key('lock', $dataset, $region), $keys);
                $this->assertContains(self::key('backoff', $dataset, $region), $keys);
            }
        }
        $this->assertContains('cat_datos_v1_backoff_api', $keys);
        $this->assertCount(3 * 2 * 3 + 1, $keys);
        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    public function test_option_keys_cover_last_good_for_every_pair(): void
    {
        $keys = Repository::optionKeys();

        $this->assertCount(6, $keys);
        $this->assertContains(self::key('lastgood', Dataset::Risk, 'nuble'), $keys);
        $this->assertContains(self::key('lastgood', Dataset::Burned, 'araucania'), $keys);
    }

    public function test_every_key_the_repository_actually_writes_is_enumerated(): void
    {
        foreach (['nuble', 'biobio', 'araucania'] as $region) {
            foreach (Dataset::cases() as $dataset) {
                $this->http->queue(self::ok($dataset, $region));
                $this->repo->refresh($region, $dataset);
            }
        }
        // An expired transient plus a failing API writes the lock and both backoff keys too.
        $this->clock->advance(901);
        $this->http->queue(new \CableATierra\Datos\Port\HttpResponse(502, ''));
        $this->repo->refresh('nuble', Dataset::Risk);

        $written = array_map(
            static fn (string $w): string => explode(':', $w)[1],
            $this->cache->writes,
        );
        $known = array_merge(Repository::transientKeys(), Repository::optionKeys());

        $this->assertNotSame([], $written);
        $this->assertSame([], array_values(array_diff($written, $known)));
    }
}
