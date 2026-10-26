<?php

declare(strict_types=1);

namespace CableATierra\Datos\Render;

use CableATierra\Datos\Detail\RegionDetail;
use CableATierra\Datos\Domain\Attribution;
use CableATierra\Datos\Domain\Freshness;
use CableATierra\Datos\Domain\Region;
use CableATierra\Datos\Format\LocalTime;
use CableATierra\Datos\Format\NumberFormat;
use CableATierra\Datos\Format\SpanishDate;
use CableATierra\Datos\Summary\DriverCopy;

/**
 * Turns region details into the stacked, no-JS detail tables. Takes details only (never fetches)
 * and never throws: a panel that fails degrades to its own "unavailable" panel, anything worse to
 * three of them. Builds plain-text view models; the templates escape every value at output.
 */
final class DetailRenderer
{
    private const UNAVAILABLE = 'Datos no disponibles por el momento';

    /** Renders started in this request; the first uses the bare region ids, later ones suffix them. */
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

    /** @param list<RegionDetail> $details In any order; missing regions count as unavailable. */
    public function render(array $details): string
    {
        $instance = ++self::$renders;
        try {
            return $this->templates->render('detail', $this->view($details, $instance));
        } catch (\Throwable) {
            return '<section class="cat-detail alignwide" data-cat-tabs="" data-default="biobio">'
                . implode('', array_map(fn (Region $r): string => $this->emptyPanel($r, $instance), Region::cases())) . '</section>';
        }
    }

    /** All three panels with the unavailable message. */
    public function unavailable(): string
    {
        return $this->render([]);
    }

    private function panelId(Region $region, int $instance): string
    {
        return $instance === 1 ? $region->value : $region->value . '-' . $instance;
    }

    /** Literal markup for a panel whose template failed; same id and heading, no hook. */
    private function emptyPanel(Region $region, int $instance): string
    {
        return '<section class="cat-detail__panel" id="' . esc_attr($this->panelId($region, $instance)) . '"><h2 class="cat-detail__title">'
            . esc_html($region->label()) . '</h2><p class="cat-detail__empty">' . self::UNAVAILABLE . '</p></section>';
    }

    /**
     * @param list<RegionDetail> $details
     * @return array<string, mixed>
     */
    private function view(array $details, int $instance): array
    {
        $byRegion = [];
        foreach ($details as $detail) {
            if (!$detail instanceof RegionDetail) {
                throw new \InvalidArgumentException('DetailRenderer expects RegionDetail instances.');
            }
            $byRegion[$detail->region] = $detail;
        }

        $nav = [];
        $panels = [];
        $tables = 0;
        $citations = [];
        foreach (Region::cases() as $region) {
            $id = $this->panelId($region, $instance);
            $nav[] = ['href' => '#' . $id, 'name' => $region->label()];
            $detail = $byRegion[$region->value] ?? RegionDetail::unavailable($region->value);
            try {
                $panel = $this->panel($detail, $region, $id, $instance);
                $panels[] = $this->templates->render('region-panel', $panel);
            } catch (\Throwable) {
                $panels[] = $this->emptyPanel($region, $instance);
                continue;
            }
            if (!$panel['unavailable']) {
                $tables++;
                // The citation backs the burned column, so it is only cited when a burned value is displayed.
                if ($detail->withData > 0) {
                    $citations[] = trim((string) $detail->citation);
                }
            }
        }

        $cited = array_values(array_unique(array_filter($citations, static fn (string $c): bool => $c !== '')));

        return [
            'nav' => $nav,
            'panels' => $panels,
            'footer' => $tables === 0 ? null : [
                'sourceUrl' => Attribution::NOFIRES_URL,
                'citations' => $citations !== [] && $cited === [] ? [Attribution::MAPBIOMAS_CITATION] : $cited,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function panel(RegionDetail $d, Region $region, string $id, int $instance): array
    {
        $panel = ['detail' => $d, 'region' => $region->value, 'id' => $id, 'name' => $region->label(), 'unavailable' => true];
        if ($d->state === Freshness::Unavailable || $d->freshnessTs === null) {
            return $panel;
        }

        $when = SpanishDate::long(LocalTime::at($d->freshnessTs));
        $rows = array_map(static fn (array $r): array => [
            'name' => $r['name'],
            'swatch' => $r['level']->slug(),
            'level' => $r['level']->label(),
            'cause' => DriverCopy::label($r['driver'], $r['level'], $r['today']) ?? '—',
            'firms' => NumberFormat::integer($r['firms']) ?? '—',
            'burned' => $r['burnedHa'] === null ? 'Sin dato' : (string) NumberFormat::hectares($r['burnedHa']),
        ], $d->rows);

        return [
            'unavailable' => false,
            'captionId' => 'cat-detail-cap-' . $region->value . '-' . $instance,
            'caption' => sprintf('Comunas de %s: nivel de alerta, focos y superficie quemada', $region->label()),
            'asof' => 'Actualizado el ' . $when,
            'banner' => $d->state === Freshness::Ok ? null : 'Datos desactualizados · Último dato: ' . $when,
            'coverage' => sprintf('%d de %d comunas con dato', $d->withData, $d->total()),
            'firmsHeader' => $d->state === Freshness::VeryStale ? 'Focos: 48 h previas al ' . SpanishDate::day(LocalTime::at($d->freshnessTs)) : 'Focos (48 h)',
            'burnedHeader' => $d->year !== null ? 'Superficie quemada ' . $d->year : 'Superficie quemada',
            'rows' => $rows,
        ] + $panel;
    }
}
