<?php

declare(strict_types=1);

namespace CableATierra\Datos\Summary;

use CableATierra\Datos\Domain\Freshness;
use CableATierra\Datos\Domain\Region;
use CableATierra\Datos\Format\LocalTime;
use CableATierra\Datos\Format\SpanishDate;

/**
 * One plain-language sentence for the whole macrozone. Text is raw (comuna names unescaped).
 * Regions are always looked up in Region order; a region with no summary counts as missing.
 */
final class MacrozonaHeadline
{
    private const MAX_NAMES = 3;

    /**
     * @param list<RegionSummary> $summaries
     * @return ?string null when no region has data
     */
    public static function compose(array $summaries, int $now): ?string
    {
        $byRegion = [];
        foreach ($summaries as $summary) {
            $byRegion[$summary->region] = $summary;
        }

        $included = [];
        $missing = [];
        foreach (Region::cases() as $region) {
            $summary = $byRegion[$region->value] ?? null;
            if ($summary === null || $summary->state === Freshness::Unavailable) {
                $missing[] = $region->label();
            } else {
                $included[] = $summary;
            }
        }
        if ($included === []) {
            return null;
        }

        $critical = [];
        $high = 0;
        $total = 0;
        foreach ($included as $summary) {
            $high += $summary->levelCounts['ALTO'] ?? 0;
            $total += $summary->comunaCount;
            foreach ($summary->criticalComunas as $comuna) {
                $critical[] = [$summary->label, $comuna];
            }
        }

        $text = $critical === []
            ? sprintf('Ninguna comuna en nivel crítico. %d de %d %s en nivel alto.', $high, $total, $high === 1 ? 'está' : 'están')
            : self::criticalSentence($critical, $now);

        return $missing === [] ? $text : $text . ' Sin datos de ' . self::join($missing) . '.';
    }

    /** @param non-empty-list<array{string, array{comunaId: string, nombre: string, driverKey: ?string, computedAt: string}}> $critical */
    private static function criticalSentence(array $critical, int $now): string
    {
        $count = count($critical);
        $groups = [];
        foreach (array_slice($critical, 0, self::MAX_NAMES) as [$label, $comuna]) {
            $ts = LocalTime::parseUtc($comuna['computedAt']);
            $date = $ts !== null && LocalTime::dateKey($ts) !== LocalTime::dateKey($now) ? SpanishDate::day(LocalTime::at($ts)) : null;
            $groups[$label . '|' . $comuna['driverKey'] . '|' . $date][] = [$label, $comuna, $date];
        }

        $segments = [];
        foreach ($groups as $members) {
            [$label, $first, $date] = $members[0];
            $clause = DriverCopy::clause($first['driverKey'], count($members) > 1, $date);
            $names = self::join(array_map(static fn (array $m): string => $m[1]['nombre'], $members));
            $segments[] = $names . ' (' . $label . ')' . ($clause !== null ? ', ' . $clause : '');
        }
        if ($count > self::MAX_NAMES) {
            $segments[] = 'y ' . ($count - self::MAX_NAMES) . ' más';
        }

        return $count . ($count === 1 ? ' comuna' : ' comunas') . ' en nivel crítico: ' . implode('; ', $segments) . '.';
    }

    /** @param list<string> $items "A", "A y B", "A, B y C" */
    private static function join(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items) . ' y ' . $last;
    }
}
