<?php

declare(strict_types=1);

namespace CableATierra\Datos\Data;

use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Domain\Region;
use CableATierra\Datos\Domain\Source;
use CableATierra\Datos\Port\Cache;
use CableATierra\Datos\Port\Clock;
use CableATierra\Datos\Port\HttpClient;
use CableATierra\Datos\Port\Scheduler;

/**
 * Cached access to the NoFires datasets, one (region, dataset) at a time.
 *
 * Nothing here throws: every failure degrades to the last-good payload or to Source::None.
 * A failure never touches the fresh transient or the last-good option; only a payload that
 * passed its normalizer is stored. Stored entries carry a schema version; anything that does
 * not match (wrong type, missing keys, older schema) is treated as absent.
 */
final class Repository
{
    public const REFRESH_HOOK = 'cat_datos_refresh';

    private const BASE_URL = 'https://nofires-backend-production.up.railway.app';
    private const SCHEMA = 1;
    private const LOCK_TTL = 30;
    private const BACKOFF_TTL = 300;
    /** A last-good older than this is considered a sign that cron is not running. */
    private const SWR_MAX_AGE = 3600;

    public function __construct(
        private readonly HttpClient $http,
        private readonly Clock $clock,
        private readonly Cache $cache,
        private readonly Scheduler $scheduler,
        private readonly RiskNormalizer $risk = new RiskNormalizer(),
        private readonly BurnedNormalizer $burned = new BurnedNormalizer(),
    ) {
    }

    /**
     * Page path (stale-while-revalidate): serve fresh data, else a recent last-good while a
     * background refresh is queued; fetch inline only when there is no usable last-good.
     */
    public function read(string $region, Dataset $dataset): DatasetResult
    {
        try {
            $enum = Region::tryFrom($region);
            if ($enum === null) {
                return DatasetResult::none();
            }

            $fresh = $this->stored($this->cache->get($this->key('', $dataset, $enum)), $dataset);
            if ($fresh !== null) {
                return $this->result(Source::Cache, $fresh);
            }

            $lastGood = $this->stored($this->cache->getOption($this->key('lastgood', $dataset, $enum)), $dataset);
            if ($lastGood !== null && $this->clock->now() - $lastGood['fetchedAt'] <= self::SWR_MAX_AGE) {
                if ($this->cache->get($this->key('backoff', $dataset, $enum)) === null) {
                    $this->scheduler->scheduleOnce(self::REFRESH_HOOK);
                }

                return $this->result(Source::LastGood, $lastGood);
            }

            return $this->refresh($region, $dataset);
        } catch (\Throwable) {
            return DatasetResult::none();
        }
    }

    /** Cron path: fresh transient -> API -> last-good -> none. */
    public function refresh(string $region, Dataset $dataset): DatasetResult
    {
        try {
            $enum = Region::tryFrom($region);
            if ($enum === null) {
                return DatasetResult::none();
            }

            $fresh = $this->stored($this->cache->get($this->key('', $dataset, $enum)), $dataset);
            if ($fresh !== null) {
                return $this->result(Source::Cache, $fresh);
            }

            $lock = $this->key('lock', $dataset, $enum);
            $backoff = $this->key('backoff', $dataset, $enum);
            if ($this->cache->get($lock) === null && $this->cache->get($backoff) === null) {
                $this->cache->set($lock, 1, self::LOCK_TTL);
                try {
                    $payload = $this->fetch($enum, $dataset);
                    if ($payload !== null) {
                        $entry = ['v' => self::SCHEMA, 'fetchedAt' => $this->clock->now(), 'payload' => $payload];
                        $this->cache->setOption($this->key('lastgood', $dataset, $enum), $entry);
                        $this->cache->set($this->key('', $dataset, $enum), $entry, $dataset->ttl());

                        return $this->result(Source::Api, $entry);
                    }
                    $this->cache->set($backoff, 1, self::BACKOFF_TTL);
                } finally {
                    try {
                        $this->cache->delete($lock);
                    } catch (\Throwable) {
                        // The lock expires on its own after LOCK_TTL.
                    }
                }
            }

            return $this->lastGood($enum, $dataset);
        } catch (\Throwable) {
            return DatasetResult::none();
        }
    }

    private function lastGood(Region $region, Dataset $dataset): DatasetResult
    {
        $entry = $this->stored($this->cache->getOption($this->key('lastgood', $dataset, $region)), $dataset);

        return $entry === null ? DatasetResult::none() : $this->result(Source::LastGood, $entry);
    }

    /** @return array<mixed>|null Normalized payload, or null on any failure. */
    private function fetch(Region $region, Dataset $dataset): ?array
    {
        $url = self::BASE_URL . match ($dataset) {
            Dataset::Risk => '/api/territory/risk-score/comunas/' . $region->value,
            Dataset::Burned => '/api/territory/public/layers?regionId=' . $region->value . '&indicators=BURNED',
        };

        try {
            $response = $this->http->get($url);
            if ($response->status !== 200 || $response->body === null) {
                return null;
            }

            return $dataset === Dataset::Risk
                ? $this->risk->normalize($response->body)
                : $this->burned->normalize($response->body);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array{v: int, fetchedAt: int, payload: array<mixed>} $entry
     */
    private function result(Source $source, array $entry): DatasetResult
    {
        return new DatasetResult($source, $entry['payload'], $entry['fetchedAt']);
    }

    /**
     * Returns the entry only if it has the current schema and a payload of the expected shape.
     *
     * @return array{v: int, fetchedAt: int, payload: array<mixed>}|null
     */
    private function stored(mixed $value, Dataset $dataset): ?array
    {
        if (
            !is_array($value)
            || ($value['v'] ?? null) !== self::SCHEMA
            || !is_int($value['fetchedAt'] ?? null)
            || !is_array($value['payload'] ?? null)
            || !$this->validPayload($value['payload'], $dataset)
        ) {
            return null;
        }

        return $value;
    }

    /** @param array<mixed> $payload */
    private function validPayload(array $payload, Dataset $dataset): bool
    {
        if ($dataset === Dataset::Burned) {
            return is_int($payload['year'] ?? null) && is_array($payload['values'] ?? null) && $payload['values'] !== [];
        }
        if ($payload === [] || !array_is_list($payload)) {
            return false;
        }
        foreach ($payload as $row) {
            if (
                !is_array($row)
                || !is_string($row['comunaId'] ?? null)
                || !is_string($row['nombreComuna'] ?? null)
                || !is_string($row['alertLevel'] ?? null)
                || !is_string($row['computedAt'] ?? null)
            ) {
                return false;
            }
        }

        return true;
    }

    /** Empty $kind is the data transient: cat_datos_v1_{dataset}_{region}. */
    private function key(string $kind, Dataset $dataset, Region $region): string
    {
        return 'cat_datos_v1_' . ($kind === '' ? '' : $kind . '_') . $dataset->value . '_' . $region->value;
    }
}
