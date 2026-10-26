<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Support;

use CableATierra\Datos\Data\DatasetResult;
use CableATierra\Datos\Domain\Source;

/** Builders for normalized repository payloads. */
trait SummaryFixtures
{
    /** 2026-10-25 12:00 UTC, which is 09:00 in Chile (UTC-3). */
    private const NOW = '2026-10-25T12:00:00Z';

    private static function now(): int
    {
        return gmmktime(12, 0, 0, 10, 25, 2026);
    }

    /** @return array<string, mixed> */
    private static function row(string $id, string $level = 'ALTO', ?int $firms = 0, ?string $driver = null, string $computedAt = self::NOW, string $name = ''): array
    {
        return [
            'comunaId' => $id,
            'nombreComuna' => $name !== '' ? $name : 'Comuna ' . $id,
            'alertLevel' => $level,
            'alertDriver' => $driver,
            'scoreComposite' => 0.5,
            'firmsCount' => $firms,
            'computedAt' => $computedAt,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function risk(array $rows, Source $source = Source::Cache): DatasetResult
    {
        return new DatasetResult($source, $rows, 1_800_000_000);
    }

    /** @param array<string, array{available: bool, burnedFraction: ?float, burnedHa: ?float}> $values */
    private static function burned(array $values, ?int $year = 2025, ?string $citation = 'MapBiomas cita', Source $source = Source::Cache): DatasetResult
    {
        return new DatasetResult($source, ['year' => $year, 'citation' => $citation, 'values' => $values], 1_800_000_000);
    }

    /** @return array{available: bool, burnedFraction: ?float, burnedHa: ?float} */
    private static function ha(?float $ha): array
    {
        return ['available' => $ha !== null, 'burnedFraction' => $ha === null ? null : 0.1, 'burnedHa' => $ha];
    }
}
