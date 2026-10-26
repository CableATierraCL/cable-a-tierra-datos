<?php

declare(strict_types=1);

namespace CableATierra\Datos\Detail;

use CableATierra\Datos\Data\DatasetResult;
use CableATierra\Datos\Domain\AlertLevel;
use CableATierra\Datos\Domain\Freshness;
use CableATierra\Datos\Domain\Region;
use CableATierra\Datos\Format\LocalTime;
use CableATierra\Datos\Summary\RegionSummarizer;

/**
 * One region's detail table as plain data: risk rows joined to burned area by GID. Pure (no I/O,
 * time only enters as `$now`); strings are raw and escaped at output.
 *
 * Row `burnedHa` is null when the comuna has no burned figure (never coerced to 0); a real 0.0
 * stays 0.0. `today` tells whether the row was computed on the same Chile calendar day as `$now`.
 *
 * @phpstan-type Row array{comunaId: string, name: string, level: AlertLevel, driver: ?string, firms: ?int, today: bool, burnedHa: ?float}
 */
final readonly class RegionDetail
{
    /**
     * @param list<array{comunaId: string, name: string, level: AlertLevel, driver: ?string, firms: ?int, today: bool, burnedHa: ?float}> $rows
     */
    public function __construct(
        public string $region,
        public string $label,
        public Freshness $state,
        public ?int $freshnessTs,
        public array $rows,
        public int $withData,
        public ?int $year,
        public ?string $citation,
    ) {
    }

    public static function build(string $regionId, DatasetResult $risk, DatasetResult $burned, int $now): self
    {
        $summarizer = new RegionSummarizer();
        $summary = $summarizer->summarize($regionId, $risk, $burned, $now);
        if ($summary->freshnessTs === null) {
            return self::unavailable($regionId);
        }

        $values = is_array($burned->payload['values'] ?? null) ? $burned->payload['values'] : [];
        $today = LocalTime::dateKey($now);
        $rows = [];
        $withData = 0;
        foreach ($summarizer->comunas($risk) as $c) {
            if ($c['computedAtLocal'] === null) {
                continue; // Untrustworthy timestamp: counted nowhere, as in the summary.
            }
            $entry = $values[$c['comunaId']] ?? null;
            $ha = is_array($entry) && ($entry['available'] ?? false) === true && (is_int($entry['burnedHa'] ?? null) || is_float($entry['burnedHa'] ?? null))
                ? (float) $entry['burnedHa']
                : null;
            $withData += $ha === null ? 0 : 1;
            $rows[] = [
                'comunaId' => $c['comunaId'],
                'name' => $c['nombre'],
                'level' => AlertLevel::from($c['alertLevel']),
                'driver' => $c['alertDriver'],
                'firms' => $c['firmsCount'],
                'today' => substr($c['computedAtLocal'], 0, 10) === $today,
                'burnedHa' => $ha,
            ];
        }

        return new self($regionId, $summary->label, $summary->state, $summary->freshnessTs, $rows, $withData, $summary->burned['year'], $summary->burned['citation']);
    }

    /** No usable risk data: no rows, state `unavailable`. */
    public static function unavailable(string $regionId): self
    {
        return new self($regionId, Region::tryFrom($regionId)?->label() ?? $regionId, Freshness::Unavailable, null, [], 0, null, null);
    }

    public function total(): int
    {
        return count($this->rows);
    }
}
