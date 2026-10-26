<?php

declare(strict_types=1);

namespace CableATierra\Datos\Render;

use CableATierra\Datos\Domain\AlertLevel;
use CableATierra\Datos\Domain\Attribution;
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
    /** Alias of the shared constant; used only when the API omits `citation`. */
    public const DEFAULT_CITATION = Attribution::MAPBIOMAS_CITATION;

    private const FIRMS_LABEL = 'Focos detectados, últimas 48 h';

    /** Renders started in this request; drives unique ids and the single `#datos` anchor. */
    private static int $renders = 0;

    private Templates $templates;

    /** @internal Test hook: restarts the per-request render count. */
    public static function resetInstances(): void
    {
        self::$renders = 0;
    }

    public function __construct(string $templates = '')
    {
        $this->templates = new Templates($templates);
    }

    /** @param list<RegionSummary> $summaries In any order; missing regions count as unavailable. */
    public function render(array $summaries, int $now): string
    {
        $instance = ++self::$renders;
        try {
            return $this->templates->render('band', $this->view($summaries, $now, $instance));
        } catch (\Throwable) {
            return $this->fallback($instance);
        }
    }

    /** The band with no data at all: heading, message and call to action. */
    public function unavailable(): string
    {
        $instance = ++self::$renders;
        try {
            return $this->templates->render('band', $this->emptyView($instance));
        } catch (\Throwable) {
            return $this->fallback($instance);
        }
    }

    /** Literal markup for when even the template cannot be rendered. Same id rules as the template. */
    private function fallback(int $instance): string
    {
        return '<section class="cat-data cat-band"' . ($instance === 1 ? ' id="datos"' : '') . ' aria-labelledby="cat-band-title-' . $instance . '"><div class="cat-band__inner">'
            . '<h2 class="cat-data__title" id="cat-band-title-' . $instance . '">El territorio, medido.</h2>'
            . '<p class="cat-band__empty">Datos no disponibles por el momento</p></div></section>';
    }

    /**
     * @param list<RegionSummary> $summaries
     * @return array<string, mixed>
     */
    private function view(array $summaries, int $now, int $instance): array
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
        // A region without a timestamp renders as an unavailable card, so it must not feed the footer either.
        $live = array_values(array_filter($ordered, static fn (RegionSummary $s): bool => $s->state !== Freshness::Unavailable && $s->freshnessTs !== null));
        if ($live === []) {
            return $this->emptyView($instance);
        }
        $cards = array_map(fn (RegionSummary $s): array => $this->card($s, $instance), $ordered);

        return [
            'uid' => $instance,
            'anchor' => $instance === 1,
            'headline' => MacrozonaHeadline::compose($ordered, $now),
            'cards' => $cards,
            'methodologyUrl' => $this->url('cat_datos_methodology_url', ''),
            // Hidden until the /datos/ page has real content; enable via the filter.
            'ctaUrl' => $this->url('cat_datos_explore_url', ''),
            'footer' => $this->footer($live, $this->shownBurnedCitations($ordered, $cards)),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyView(int $instance): array
    {
        return ['uid' => $instance, 'anchor' => $instance === 1, 'headline' => null, 'cards' => [], 'methodologyUrl' => '', 'ctaUrl' => $this->url('cat_datos_explore_url', ''), 'footer' => null];
    }

    /** @return array<string, mixed> */
    private function card(RegionSummary $s, int $instance): array
    {
        $card = ['uid' => $instance, 'slug' => $s->region, 'name' => $s->label, 'unavailable' => $s->state === Freshness::Unavailable];
        if ($card['unavailable'] || $s->freshnessTs === null) {
            return ['unavailable' => true] + $card;
        }

        $when = LocalTime::at($s->freshnessTs);
        $stale = $s->state === Freshness::Stale || $s->state === Freshness::VeryStale;
        $levels = [];
        $classified = 0;
        foreach (AlertLevel::known() as $level) {
            $n = $s->levelCounts[$level->value] ?? 0;
            $classified += $n;
            $name = $level->label();
            $levels[] = ['slug' => $level->slug(), 'name' => $name, 'n' => $n, 'sr' => sprintf('%s: %d %s', $name, $n, $n === 1 ? 'comuna' : 'comunas')];
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
     * Citations of the regions whose card actually shows a burned figure (empty when none does).
     *
     * @param list<RegionSummary> $ordered
     * @param list<array<string, mixed>> $cards Same order as $ordered.
     * @return list<string>|null null when no card shows a burned figure.
     */
    private function shownBurnedCitations(array $ordered, array $cards): ?array
    {
        $citations = [];
        $shown = false;
        foreach ($ordered as $i => $s) {
            if (($cards[$i]['burned'] ?? null) === null) {
                continue;
            }
            $shown = true;
            $citations[] = trim((string) $s->burned['citation']);
        }
        if (!$shown) {
            return null;
        }
        $citations = array_values(array_unique(array_filter($citations, static fn (string $c): bool => $c !== '')));

        // The default is only for a layer that omits `citation`; the API's own text always wins.
        return $citations === [] ? [self::DEFAULT_CITATION] : $citations;
    }

    /**
     * @param non-empty-list<RegionSummary> $live Every entry has a non-null freshnessTs.
     * @param list<string>|null $citations
     * @return array<string, mixed>
     */
    private function footer(array $live, ?array $citations): array
    {
        $oldest = min(array_map(static fn (RegionSummary $s): int => $s->freshnessTs ?? PHP_INT_MAX, $live));

        return [
            'updated' => 'Actualizado el ' . SpanishDate::long(LocalTime::at($oldest)) . ' (hora de Chile).',
            'sourceUrl' => Attribution::NOFIRES_URL,
            'citations' => $citations ?? [],
        ];
    }

    /**
     * Filtered URL, or '' when it is not an http(s) URL or a site-relative path (templates then hide the link).
     * esc_url_raw() with an http/https allow-list strips other schemes ("javascript:x" becomes "x") and keeps
     * paths that start with "/", so the result is re-checked instead of trusted.
     */
    private function url(string $filter, string $default): string
    {
        $value = apply_filters($filter, $default);
        if (!is_string($value) || trim($value) === '') {
            return '';
        }
        $clean = esc_url_raw(trim($value), ['http', 'https']);

        return preg_match('#^(?:https?://\S|/(?!/))#i', $clean) === 1 ? $clean : '';
    }

}
