<?php

declare(strict_types=1);

namespace CableATierra\Datos\Summary;

use CableATierra\Datos\Data\Repository;
use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Port\Clock;

/**
 * Backing logic of the public functions. Always returns arrays and never throws: any failure
 * degrades to the `unavailable` shape.
 */
final class Api
{
    /** @return array<string, mixed> See cat_datos_get_region_summary() for the shape. */
    public static function summary(Repository $repository, Clock $clock, string $region): array
    {
        try {
            return (new RegionSummarizer())
                ->summarize($region, $repository->read($region, Dataset::Risk), $repository->read($region, Dataset::Burned), $clock->now())
                ->toArray();
        } catch (\Throwable) {
            return RegionSummary::unavailable($region)->toArray();
        }
    }

    /** @return list<array<string, mixed>> See cat_datos_get_comunas() for the shape. */
    public static function comunas(Repository $repository, string $region): array
    {
        try {
            return (new RegionSummarizer())->comunas($repository->read($region, Dataset::Risk));
        } catch (\Throwable) {
            return [];
        }
    }
}
