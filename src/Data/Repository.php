<?php

declare(strict_types=1);

namespace CableATierra\Datos\Data;

use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Domain\Region;
use CableATierra\Datos\Domain\Source;
use CableATierra\Datos\Port\Cache;
use CableATierra\Datos\Port\Clock;
use CableATierra\Datos\Port\HttpClient;
use CableATierra\Datos\Port\Logger;
use CableATierra\Datos\Port\NullLogger;
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
    /** Outage guard shared by every pair: any transport/HTTP-level failure pauses all requests. */
    private const API_BACKOFF_KEY = 'cat_datos_v1_backoff_api';
    /** A last-good older than ttl + this many seconds means cron is evidently not running. */
    private const CRON_GRACE = 21600;
    /** Cron refreshes a pair this many seconds before its transient would expire (covers cron lag). */
    private const CRON_EARLY = 120;
    private const INLINE_TIMEOUT = 3.0;
    private const CRON_TIMEOUT = 5.0;

    /** Inline (page path) fetches already made by this instance; the budget is one per request. */
    private int $inlineFetches = 0;

    public function __construct(
        private readonly HttpClient $http,
        private readonly Clock $clock,
        private readonly Cache $cache,
        private readonly Scheduler $scheduler,
        private readonly RiskNormalizer $risk = new RiskNormalizer(),
        private readonly BurnedNormalizer $burned = new BurnedNormalizer(),
        private readonly Logger $logger = new NullLogger(),
    ) {
    }

    /**
     * Every transient this class can write (data, lock, backoff per pair, plus the API-wide
     * backoff). The uninstaller deletes exactly these.
     *
     * @return list<string>
     */
    public static function transientKeys(): array
    {
        $keys = [self::API_BACKOFF_KEY];
        foreach (Region::cases() as $region) {
            foreach (Dataset::cases() as $dataset) {
                foreach (['', 'lock', 'backoff'] as $kind) {
                    $keys[] = self::key($kind, $dataset, $region);
                }
            }
        }

        return $keys;
    }

    /**
     * Every option this class can write: the last-good payload per pair.
     *
     * @return list<string>
     */
    public static function optionKeys(): array
    {
        $keys = [];
        foreach (Region::cases() as $region) {
            foreach (Dataset::cases() as $dataset) {
                $keys[] = self::key('lastgood', $dataset, $region);
            }
        }

        return $keys;
    }

    /**
     * Page path (stale-while-revalidate): serve fresh data, else the last-good of any age while a
     * background refresh is queued. A blocking fetch (3 s, at most one per request, never during
     * a backoff) happens only with no last-good at all or one more than ttl + 6 h old.
     * Create one Repository per request: the inline budget lives on the instance.
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
            // A negative age (clock change) is stale-but-servable: it goes through cron, not inline.
            $tooOld = $lastGood !== null
                && $this->clock->now() - $lastGood['fetchedAt'] > $dataset->ttl() + self::CRON_GRACE;
            if (($lastGood === null || $tooOld) && $this->inlineAllowed($enum, $dataset)) {
                return $this->load($enum, $dataset, true);
            }

            if (
                $this->cache->get($this->key('backoff', $dataset, $enum)) === null
                && $this->cache->get(self::API_BACKOFF_KEY) === null
            ) {
                try {
                    $this->scheduler->scheduleOnce(self::REFRESH_HOOK);
                } catch (\Throwable) {
                    // Best effort: a scheduling failure must not drop a servable result.
                }
            }

            return $lastGood === null ? DatasetResult::none() : $this->result(Source::LastGood, $lastGood);
        } catch (\Throwable) {
            return DatasetResult::none();
        }
    }

    /**
     * Cron path: API (5 s) -> last-good -> none, but only for a pair that is due. A pair is due
     * when it has no last-good or its last-good is at least ttl - CRON_EARLY seconds old, so a
     * transient written a few seconds after the previous grid tick cannot make the next tick skip.
     * Otherwise the still-fresh transient is served as is.
     */
    public function refresh(string $region, Dataset $dataset): DatasetResult
    {
        try {
            $enum = Region::tryFrom($region);

            return $enum === null ? DatasetResult::none() : $this->load($enum, $dataset, false, $this->cronDue($enum, $dataset));
        } catch (\Throwable) {
            return DatasetResult::none();
        }
    }

    private function cronDue(Region $region, Dataset $dataset): bool
    {
        $lastGood = $this->stored($this->cache->getOption($this->key('lastgood', $dataset, $region)), $dataset);

        return $lastGood === null || $this->clock->now() - $lastGood['fetchedAt'] >= $dataset->ttl() - self::CRON_EARLY;
    }

    private function inlineAllowed(Region $region, Dataset $dataset): bool
    {
        return $this->inlineFetches === 0
            && $this->cache->get(self::API_BACKOFF_KEY) === null
            && $this->cache->get($this->key('backoff', $dataset, $region)) === null;
    }

    private function load(Region $enum, Dataset $dataset, bool $inline, bool $due = false): DatasetResult
    {
        try {
            $fresh = $this->stored($this->cache->get($this->key('', $dataset, $enum)), $dataset);
            if ($fresh !== null && !$due) {
                return $this->result(Source::Cache, $fresh);
            }

            $lock = $this->key('lock', $dataset, $enum);
            $backoff = $this->key('backoff', $dataset, $enum);
            if (
                $this->cache->get($lock) === null
                && $this->cache->get($backoff) === null
                && $this->cache->get(self::API_BACKOFF_KEY) === null
                && (!$inline || $this->inlineFetches === 0)
            ) {
                $this->cache->set($lock, 1, self::LOCK_TTL);
                try {
                    if ($inline) {
                        $this->inlineFetches++;
                    }
                    $payload = $this->fetch($enum, $dataset, $inline ? self::INLINE_TIMEOUT : self::CRON_TIMEOUT);
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
    private function fetch(Region $region, Dataset $dataset, float $timeout): ?array
    {
        $url = self::BASE_URL . match ($dataset) {
            Dataset::Risk => '/api/territory/risk-score/comunas/' . $region->value,
            Dataset::Burned => '/api/territory/public/layers?regionId=' . $region->value . '&indicators=BURNED',
        };

        try {
            try {
                $response = $this->http->get($url, $timeout);
            } catch (\Throwable $e) {
                $this->warn($region, $dataset, 'unexpected ' . $e::class . ': ' . $e->getMessage());
                $response = null;
            }
            if ($response === null || $response->status !== 200 || $response->body === null) {
                if ($response !== null) {
                    // Status and transport error only: a response body may carry anything.
                    $this->warn($region, $dataset, match (true) {
                        $response->status === null => 'transport error: ' . ($response->error ?? 'unknown'),
                        $response->status !== 200 => 'HTTP ' . $response->status,
                        default => 'empty body',
                    });
                }
                // Transport/HTTP-level failure: the API itself is unhealthy, pause every pair.
                $this->cache->set(self::API_BACKOFF_KEY, 1, self::BACKOFF_TTL);

                return null;
            }

            $payload = $dataset === Dataset::Risk
                ? $this->risk->normalize($response->body)
                : $this->burned->normalize($response->body);
            if ($payload === null) {
                $this->warn($region, $dataset, 'invalid payload');
            }

            return $payload;
        } catch (\Throwable $e) {
            $this->warn($region, $dataset, 'unexpected ' . $e::class . ': ' . $e->getMessage());

            return null;
        }
    }

    /** One line, bounded, control characters removed. A broken logger must not break a refresh. */
    private function warn(Region $region, Dataset $dataset, string $reason): void
    {
        try {
            $reason = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/', ' ', $reason));
            $this->logger->warning($dataset->value . '/' . $region->value . ': ' . (strlen($reason) > 200 ? substr($reason, 0, 200) : $reason));
        } catch (\Throwable) {
            // Logging is best effort.
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
    private static function key(string $kind, Dataset $dataset, Region $region): string
    {
        return 'cat_datos_v1_' . ($kind === '' ? '' : $kind . '_') . $dataset->value . '_' . $region->value;
    }
}
