<?php

declare(strict_types=1);

namespace CableATierra\Datos\Summary;

use CableATierra\Datos\Data\DatasetResult;
use CableATierra\Datos\Domain\AlertDriver;
use CableATierra\Datos\Domain\AlertLevel;
use CableATierra\Datos\Domain\Freshness;
use CableATierra\Datos\Domain\Region;
use CableATierra\Datos\Domain\Source;
use CableATierra\Datos\Format\LocalTime;

/**
 * Pure aggregation of one region's normalized payloads. No I/O; time only enters as `$now`.
 * Stored payloads are re-checked defensively, so a malformed entry degrades instead of throwing.
 */
final class RegionSummarizer
{
    /** Fixed display order of classified levels. */
    public const LEVELS = ['CRITICO', 'ALTO', 'PREVENTIVO', 'NORMAL'];

    /** Oldest-data age (seconds) up to which a region is fresh; the methodology copy derives from it. */
    public const OK_MAX_AGE = 86400;
    /** Age (seconds) up to which data is stale rather than very stale. */
    public const STALE_MAX_AGE = 172800;
    private const NO_BURNED = ['available' => false, 'year' => null, 'totalHa' => null, 'comunasWithData' => 0, 'comunasTotal' => 0, 'citation' => null];

    public function summarize(string $regionId, DatasetResult $risk, DatasetResult $burned, int $now): RegionSummary
    {
        $region = Region::tryFrom($regionId);
        if ($region === null) {
            return RegionSummary::unavailable($regionId);
        }

        $sources = ['risk' => $risk->source->value, 'burned' => $burned->source->value];
        $burnedSummary = $this->burned($burned);
        $rows = $this->rows($risk);

        $counts = array_fill_keys(self::LEVELS, 0);
        $unclassified = 0;
        $firms = 0;
        $firmsSeen = false;
        $firmsMissing = 0;
        $oldest = null;
        $critical = [];
        $used = 0;
        foreach ($rows as $row) {
            // A row whose computedAt cannot be parsed is untrustworthy: it counts nowhere.
            $computedAt = is_string($row['computedAt'] ?? null) ? $row['computedAt'] : '';
            $ts = LocalTime::parseUtc($computedAt);
            if ($ts === null) {
                continue;
            }
            $used++;

            $level =AlertLevel::fromApi(is_string($row['alertLevel'] ?? null) ? $row['alertLevel'] : '');
            if ($level === AlertLevel::Unknown) {
                $unclassified++;
            } else {
                $counts[$level->value]++;
            }

            if (is_int($row['firmsCount'] ?? null)) {
                $firms += $row['firmsCount'];
                $firmsSeen = true;
            } else {
                $firmsMissing++;
            }

            if ($oldest === null || $ts < $oldest) {
                $oldest = $ts;
            }

            if ($level === AlertLevel::Critico) {
                $driver = is_string($row['alertDriver'] ?? null) ? AlertDriver::tryFrom($row['alertDriver']) : null;
                $critical[] = [
                    'comunaId' => (string) ($row['comunaId'] ?? ''),
                    'nombre' => (string) ($row['nombreComuna'] ?? ''),
                    'driverKey' => $driver?->value,
                    'computedAt' => $computedAt,
                ];
            }
        }

        if ($oldest === null) {
            return RegionSummary::unavailable($regionId, $region->label(), $burnedSummary, $sources);
        }

        usort($critical, static fn (array $a, array $b): int => strcmp(self::sortKey($a['nombre']), self::sortKey($b['nombre'])));
        $age = max(0, $now - $oldest);

        return new RegionSummary(
            $regionId,
            $region->label(),
            match (true) {
                $age <= self::OK_MAX_AGE => Freshness::Ok,
                $age <= self::STALE_MAX_AGE => Freshness::Stale,
                default => Freshness::VeryStale,
            },
            $firmsSeen ? $firms : null,
            $firmsMissing,
            $counts,
            $unclassified,
            $used,
            $critical,
            $burnedSummary,
            $oldest,
            $age,
            $sources,
        );
    }

    /**
     * Normalized comuna rows, most severe first, then by name (accent-aware). Empty without data.
     *
     * @return list<array{comunaId: string, nombre: string, alertLevel: string, alertDriver: ?string, score: ?float, firmsCount: ?int, computedAtLocal: ?string}>
     */
    public function comunas(DatasetResult $risk): array
    {
        $rows = array_map(static function (array $r): array {
            $ts = is_string($r['computedAt'] ?? null) ? LocalTime::parseUtc($r['computedAt']) : null;

            return [
                'comunaId' => (string) ($r['comunaId'] ?? ''),
                'nombre' => (string) ($r['nombreComuna'] ?? ''),
                'alertLevel' => AlertLevel::fromApi(is_string($r['alertLevel'] ?? null) ? $r['alertLevel'] : '')->value,
                'alertDriver' => is_string($r['alertDriver'] ?? null) ? AlertDriver::tryFrom($r['alertDriver'])?->value : null,
                'score' => is_float($r['scoreComposite'] ?? null) ? $r['scoreComposite'] : null,
                'firmsCount' => is_int($r['firmsCount'] ?? null) ? $r['firmsCount'] : null,
                'computedAtLocal' => $ts === null ? null : LocalTime::at($ts)->format('Y-m-d H:i'),
            ];
        }, $this->rows($risk));

        usort($rows, static fn (array $a, array $b): int => [AlertLevel::from($a['alertLevel'])->severity(), self::sortKey($a['nombre'])]
            <=> [AlertLevel::from($b['alertLevel'])->severity(), self::sortKey($b['nombre'])]);

        return $rows;
    }

    /** @return list<array<mixed>> */
    private function rows(DatasetResult $risk): array
    {
        if ($risk->source === Source::None || $risk->payload === null) {
            return [];
        }

        return array_values(array_filter($risk->payload, 'is_array'));
    }

    /** @return array{available: bool, year: ?int, totalHa: ?float, comunasWithData: int, comunasTotal: int, citation: ?string} */
    private function burned(DatasetResult $result): array
    {
        $payload = $result->payload;
        $values = $payload['values'] ?? null;
        if ($result->source === Source::None || !is_array($values)) {
            return self::NO_BURNED;
        }

        $sum = 0.0;
        $with = 0;
        foreach ($values as $entry) {
            if (is_array($entry) && ($entry['available'] ?? false) === true && (is_int($entry['burnedHa'] ?? null) || is_float($entry['burnedHa'] ?? null))) {
                $sum += (float) $entry['burnedHa'];
                $with++;
            }
        }

        return [
            'available' => $with > 0,
            'year' => is_int($payload['year'] ?? null) ? $payload['year'] : null,
            'totalHa' => $with > 0 ? $sum : null,
            'comunasWithData' => $with,
            'comunasTotal' => count($values),
            'citation' => is_string($payload['citation'] ?? null) && $payload['citation'] !== '' ? $payload['citation'] : null,
        ];
    }

    /** Accent-folded ASCII key; "ñ" sorts after "n" as in Spanish. No mbstring or intl needed. */
    private static function sortKey(string $name): string
    {
        return strtolower(strtr($name, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n{',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n{',
        ]));
    }
}
