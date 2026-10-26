<?php

declare(strict_types=1);

namespace CableATierra\Datos\Render;

use CableATierra\Datos\Domain\Freshness;
use CableATierra\Datos\Domain\Region;
use CableATierra\Datos\Format\LocalTime;
use CableATierra\Datos\Format\NumberFormat;
use CableATierra\Datos\Format\SpanishDate;
use CableATierra\Datos\Summary\MacrozonaHeadline;
use CableATierra\Datos\Summary\RegionSummary;

/**
 * Turns region summaries into the home band markup. Takes summaries only (never fetches) and
 * never throws: any failure degrades to the "unavailable" markup. This class builds plain-text
 * view models; the templates under templates/ escape every value at output time.
 */
final class BandRenderer
{
    /** Used when the burned layer carries no citation. TODO(content): replace with MapBiomas' official wording. */
    public const DEFAULT_CITATION = 'Proyecto MapBiomas Chile (chile.mapbiomas.org).';

    private const NOFIRES_URL = 'https://nofires-latam.vercel.app';
    private const FIRMS_LABEL = 'Focos detectados, últimas 48 h';

    /** Level key => [css slug, display name]. Fixed order, most severe first. */
    private const LEVELS = [
        'CRITICO' => ['critico', 'Crítico'],
        'ALTO' => ['alto', 'Alto'],
        'PREVENTIVO' => ['preventivo', 'Preventivo'],
        'NORMAL' => ['normal', 'Normal'],
    ];

    private string $templates;

    public function __construct(string $templates = '')
    {
        $this->templates = $templates !== '' ? $templates : dirname(__DIR__, 2) . '/templates';
    }

    /** @param list<RegionSummary> $summaries In any order; missing regions count as unavailable. */
    public function render(array $summaries, int $now): string
    {
        try {
            return $this->template('band', $this->view($summaries, $now));
        } catch (\Throwable) {
            return $this->unavailable();
        }
    }

    /** The band with no data at all: heading, message and call to action. */
    public function unavailable(): string
    {
        try {
            return $this->template('band', $this->emptyView());
        } catch (\Throwable) {
            return '<section class="cat-data cat-band" id="datos" aria-labelledby="cat-band-title"><div class="cat-band__inner">'
                . '<h2 class="cat-data__title" id="cat-band-title">El territorio, medido.</h2>'
                . '<p class="cat-band__empty">Datos no disponibles por el momento</p></div></section>';
        }
    }

    /**
     * @param list<RegionSummary> $summaries
     * @return array<string, mixed>
     */
    private function view(array $summaries, int $now): array
    {
        $byRegion = [];
        foreach ($summaries as $summary) {
            if (!$summary instanceof RegionSummary) {
                throw new \InvalidArgumentException('BandRenderer expects RegionSummary instances.');
            }
            $byRegion[$summary->region] = $summary;
        }
        $ordered = [];
        foreach (Region::cases() as $region) {
            $ordered[] = $byRegion[$region->value] ?? RegionSummary::unavailable($region->value, $region->label());
        }
        $live = array_values(array_filter($ordered, static fn (RegionSummary $s): bool => $s->state !== Freshness::Unavailable));
        if ($live === []) {
            return $this->emptyView();
        }

        return [
            'headline' => MacrozonaHeadline::compose($ordered, $now),
            'cards' => array_map(fn (RegionSummary $s): array => $this->card($s), $ordered),
            'methodologyUrl' => $this->url('cat_datos_methodology_url', ''),
            'ctaUrl' => $this->url('cat_datos_explore_url', home_url('/datos/')),
            'footer' => $this->footer($live),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyView(): array
    {
        return ['headline' => null, 'cards' => [], 'methodologyUrl' => '', 'ctaUrl' => $this->url('cat_datos_explore_url', home_url('/datos/')), 'footer' => null];
    }

    /** @return array<string, mixed> */
    private function card(RegionSummary $s): array
    {
        $card = ['slug' => $s->region, 'name' => $s->label, 'unavailable' => $s->state === Freshness::Unavailable];
        if ($card['unavailable'] || $s->freshnessTs === null) {
            return ['unavailable' => true] + $card;
        }

        $when = LocalTime::at($s->freshnessTs);
        $stale = $s->state === Freshness::Stale || $s->state === Freshness::VeryStale;
        $levels = [];
        $classified = 0;
        foreach (self::LEVELS as $key => [$slug, $name]) {
            $n = $s->levelCounts[$key] ?? 0;
            $classified += $n;
            $levels[] = ['slug' => $slug, 'name' => $name, 'n' => $n];
        }
        $b = $s->burned;
        $burned = null;
        if ($b['available'] && $b['totalHa'] !== null) {
            $burned = NumberFormat::hectares($b['totalHa']);
            if ($b['comunasWithData'] < $b['comunasTotal']) {
                $burned .= sprintf(' (%d de %d comunas)', $b['comunasWithData'], $b['comunasTotal']);
            }
        }

        return $card + [
            'stale' => $stale,
            'asof' => $stale ? 'Último dato: ' . SpanishDate::short($when) : null,
            'firmsLabel' => $s->state === Freshness::VeryStale ? 'Focos, 48 h previas al ' . SpanishDate::day($when) : self::FIRMS_LABEL,
            'firms' => NumberFormat::integer($s->firmsTotal),
            'levels' => $levels,
            'classified' => $classified,
            'burnedLabel' => $b['year'] !== null ? 'Superficie quemada, ' . $b['year'] : 'Superficie quemada',
            'burned' => $burned,
        ];
    }

    /**
     * @param non-empty-list<RegionSummary> $live
     * @return array<string, mixed>
     */
    private function footer(array $live): array
    {
        $citations = [];
        foreach ($live as $s) {
            $citations[] = (string) $s->burned['citation'];
        }
        $citations = array_values(array_unique(array_filter($citations, static fn (string $c): bool => $c !== '')));

        return [
            'updated' => 'Actualizado el ' . SpanishDate::long(LocalTime::at(min(array_map(static fn (RegionSummary $s): int => (int) $s->freshnessTs, $live)))) . ' (hora de Chile).',
            'sourceUrl' => self::NOFIRES_URL,
            'citations' => $citations === [] ? [self::DEFAULT_CITATION] : $citations,
        ];
    }

    private function url(string $filter, string $default): string
    {
        $value = apply_filters($filter, $default);

        return is_string($value) ? trim($value) : '';
    }

    /** @param array<string, mixed> $view */
    private function template(string $name, array $view): string
    {
        $file = $this->templates . '/' . $name . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Missing template ' . $name);
        }
        $partial = fn (string $n, array $v): string => $this->template($n, $v);
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $file, array $view, callable $partial): void {
                require $file;
            })($file, $view, $partial);

            return trim((string) ob_get_clean());
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }
}
