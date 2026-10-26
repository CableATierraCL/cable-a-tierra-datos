<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Render;

use Brain\Monkey;
use Brain\Monkey\Actions;
use CableATierra\Datos\Data\DatasetResult;
use CableATierra\Datos\Detail\RegionDetail;
use CableATierra\Datos\Domain\Attribution;
use CableATierra\Datos\Render\DetailRenderer;
use CableATierra\Datos\Tests\Support\DetailHtml;
use CableATierra\Datos\Tests\Support\SummaryFixtures;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DetailRendererTest extends TestCase
{
    use DetailHtml;
    use MockeryPHPUnitIntegration;
    use SummaryFixtures;

    private const HOOK = 'cat_datos_detail_region_after_summary';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        DetailRenderer::resetInstances();
        $this->stubEscapers();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @param list<RegionDetail> $details */
    private function html(array $details): string
    {
        return (new DetailRenderer())->render($details);
    }

    private function unavailableDetail(string $region): RegionDetail
    {
        return RegionDetail::unavailable($region);
    }

    // ---- structure ---------------------------------------------------------------------------

    public function test_it_renders_the_root_the_inert_nav_and_three_panels_in_fixed_order(): void
    {
        // Out of order on purpose: the renderer fixes the order.
        $x = $this->xpath($this->html([$this->detail('araucania'), $this->detail('nuble'), $this->detail('biobio')]));

        $root = $x->query('/section[contains(concat(" ", @class, " "), " cat-detail ") and contains(concat(" ", @class, " "), " alignwide ")]');
        $this->assertSame(1, $root->length);
        $this->assertSame('biobio', $root->item(0)->attributes->getNamedItem('data-default')?->nodeValue);
        $this->assertNotNull($root->item(0)->attributes->getNamedItem('data-cat-tabs'));
        $this->assertSame(['nuble', 'biobio', 'araucania'], array_map(static fn ($n) => $n->nodeValue, iterator_to_array($x->query('//section[contains(@class,"cat-detail__panel")]/@id'))));
        $this->assertSame(['Ñuble', 'Biobío', 'La Araucanía'], array_map(static fn ($n) => trim($n->textContent), iterator_to_array($x->query('//section[contains(@class,"cat-detail__panel")]/h2'))));
        $this->assertSame(['#nuble', '#biobio', '#araucania'], array_map(static fn ($n) => $n->nodeValue, iterator_to_array($x->query('//ul[contains(@class,"cat-detail__nav")]/li/a/@href'))));
        $this->assertSame(0, $x->query('//*[@role="tablist" or @role="tab" or @role="tabpanel"]')->length, 'those roles are added by JS');
        $this->assertSame(0, $x->query('//section//@hidden | //section/@aria-hidden | //ul/@aria-hidden')->length);
    }

    public function test_each_available_panel_has_exactly_one_accessible_table(): void
    {
        $x = $this->xpath($this->html($this->three()));

        $this->assertSame(3, $x->query('//table')->length);
        foreach ([0, 1, 2] as $i) {
            $panel = $this->panel($x, $i);
            $this->assertSame(1, $x->query('.//table', $panel)->length);
            $this->assertStringStartsWith('Comunas de', $this->text($x, './/table/caption', $panel));
            $this->assertSame(
                ['Comuna', 'Nivel', 'Causa', 'Focos (48 h)', 'Superficie quemada 2025'],
                array_map(static fn ($n) => trim($n->textContent), iterator_to_array($x->query('.//thead/tr/th[@scope="col"]', $panel))),
            );
            $this->assertSame(0, $x->query('.//th[not(@scope)]', $panel)->length);
            $rows = $x->query('.//tbody/tr', $panel);
            $this->assertSame(2, $rows->length);
            foreach ($rows as $tr) {
                $this->assertSame(1, $x->query('./th[@scope="row"]', $tr)->length);
                $this->assertSame(1, $x->query('./*[1][self::th]', $tr)->length);
                $this->assertSame(4, $x->query('./td', $tr)->length);
            }
        }
    }

    public function test_the_table_sits_in_a_focusable_labelled_scroll_region_with_unique_ids(): void
    {
        $x = $this->xpath($this->html($this->three()));

        $regions = $x->query('//div[@role="region"]');
        $this->assertSame(3, $regions->length);
        foreach ($regions as $region) {
            $this->assertSame('0', $region->attributes->getNamedItem('tabindex')?->nodeValue);
            $label = $region->attributes->getNamedItem('aria-labelledby')?->nodeValue;
            $this->assertSame(1, $x->query('.//caption[@id="' . $label . '"]', $region)->length, 'aria-labelledby resolves to the caption');
        }
        $ids = array_map(static fn ($n) => $n->nodeValue, iterator_to_array($x->query('//@id')));
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertSame(0, $x->query('//tr/@tabindex | //td/@tabindex | //th/@tabindex')->length);
    }

    public function test_a_second_render_in_the_same_request_suffixes_every_id(): void
    {
        $renderer = new DetailRenderer();
        $first = $renderer->render($this->three());
        $second = $renderer->render($this->three());

        $this->assertStringContainsString('id="biobio"', $first);
        $this->assertStringNotContainsString('id="biobio"', $second);
        $x = $this->xpath($second);
        $this->assertSame(['biobio-2'], array_map(static fn ($n) => $n->nodeValue, iterator_to_array($x->query('//section[contains(@class,"cat-detail__panel")]/@id[. = "biobio-2"]'))));
        $this->assertSame(['#nuble-2', '#biobio-2', '#araucania-2'], array_map(static fn ($n) => $n->nodeValue, iterator_to_array($x->query('//ul[contains(@class,"cat-detail__nav")]//@href'))));
    }

    // ---- summary: timestamp, coverage, banners -----------------------------------------------

    public function test_one_timestamp_per_panel_and_none_in_the_table_body(): void
    {
        $x = $this->xpath($this->html($this->three()));

        foreach ([0, 1, 2] as $i) {
            $panel = $this->panel($x, $i);
            $this->assertSame(1, substr_count($panel->textContent, 'Actualizado el'));
            $this->assertSame('Actualizado el 25 de octubre de 2026, 07:00 (hora de Chile)', $this->text($x, './/p[contains(@class,"cat-detail__asof")]', $panel));
            $this->assertDoesNotMatchRegularExpression('/\d{1,2}:\d{2}/', $this->text($x, './/tbody', $panel));
        }
    }

    public function test_the_timestamp_is_the_oldest_computed_at_in_the_region(): void
    {
        $d = $this->detail('biobio', [self::row('a', 'ALTO', 1, null, '2026-10-25T10:00:00Z', 'A'), self::row('b', 'ALTO', 1, null, '2026-10-25T01:30:00Z', 'B')]);

        $x = $this->xpath($this->html([$d]));

        $this->assertSame('Actualizado el 24 de octubre de 2026, 22:30 (hora de Chile)', $this->text($x, '//p[contains(@class,"cat-detail__asof")]'));
    }

    public function test_the_coverage_line_comes_right_before_the_table(): void
    {
        $d = $this->detail('biobio', null, self::burned(['biobio1' => self::ha(5.0)]));

        $x = $this->xpath($this->html([$d]));

        $this->assertSame('1 de 2 comunas con dato de superficie quemada', $this->text($x, '//p[contains(@class,"cat-detail__coverage")]'));
        $this->assertSame(1, $x->query('//p[contains(@class,"cat-detail__coverage")]/following-sibling::*[1][self::div[@role="region"]]')->length);
    }

    /** @return array<string, array{int, bool, bool}> age, banner, relabelled focos header */
    public static function ages(): array
    {
        return [
            'exactly 24 h' => [86400, false, false],
            '24 h and a second' => [86401, true, false],
            'exactly 48 h' => [172800, true, false],
            '48 h and a second' => [172801, true, true],
        ];
    }

    #[DataProvider('ages')]
    public function test_the_stale_banner_and_the_focos_header_follow_the_freshness_boundaries(int $age, bool $banner, bool $relabelled): void
    {
        $d = $this->detail('biobio', [self::row('a', 'ALTO', 1, null, gmdate('Y-m-d\TH:i:s\Z', self::now() - $age), 'A')]);

        $x = $this->xpath($this->html([$d]));

        $this->assertSame($banner ? 1 : 0, $x->query('//*[@role="status"]')->length);
        $headers = array_map(static fn ($n) => trim($n->textContent), iterator_to_array($x->query('//thead/tr/th')));
        $this->assertSame($relabelled ? 'Focos: 48 h previas al ' . ($age === 172801 ? '23 oct' : '') : 'Focos (48 h)', $headers[3]);
    }

    public function test_the_stale_banner_is_text_with_the_last_data_time(): void
    {
        $d = $this->detail('biobio', [self::row('a', 'ALTO', 1, null, '2026-10-24T06:00:00Z', 'A')]);

        $x = $this->xpath($this->html([$d]));

        $this->assertSame('Datos desactualizados · Último dato: 24 de octubre de 2026, 03:00 (hora de Chile)', $this->text($x, '//*[@role="status"]'));
        $this->assertSame(1, $x->query('//table')->length, 'a stale panel still shows its table');
    }

    public function test_one_stale_region_does_not_change_the_others(): void
    {
        $stale = $this->detail('biobio', [self::row('a', 'ALTO', 1, null, '2026-10-23T10:00:00Z', 'A')]);

        $x = $this->xpath($this->html([$this->detail('nuble'), $stale, $this->detail('araucania')]));

        $this->assertSame(1, $x->query('//*[@role="status"]')->length);
        $this->assertSame(0, $x->query('(//section[contains(@class,"cat-detail__panel")])[1]//*[@role="status"]')->length);
        $this->assertSame('Focos (48 h)', $this->text($x, '(//section[contains(@class,"cat-detail__panel")])[1]//thead/tr/th[4]'));
    }

    // ---- unavailable -------------------------------------------------------------------------

    public function test_an_unavailable_region_keeps_its_panel_id_and_heading_but_has_no_table(): void
    {
        $x = $this->xpath($this->html([$this->unavailableDetail('nuble'), $this->detail('biobio'), $this->detail('araucania')]));

        $panel = $this->panel($x, 0);
        $this->assertSame('nuble', $panel->attributes->getNamedItem('id')?->nodeValue);
        $this->assertSame('Ñuble', $this->text($x, './h2', $panel));
        $this->assertSame('Datos no disponibles por el momento', $this->text($x, './/p[contains(@class,"cat-detail__empty")]', $panel));
        $this->assertSame(0, $x->query('.//table | .//*[@role="status"] | .//p[contains(@class,"cat-detail__asof")]', $panel)->length);
        $this->assertSame(2, $x->query('//table')->length);
    }

    public function test_with_everything_unavailable_it_still_renders_three_panels_and_no_attribution(): void
    {
        $x = $this->xpath($this->html([]));

        $this->assertSame(3, $x->query('//section[contains(@class,"cat-detail__panel")]')->length);
        $this->assertSame(3, $x->query('//p[contains(@class,"cat-detail__empty")]')->length);
        $this->assertSame(0, $x->query('//table | //footer')->length);
        $this->assertSame(['#nuble', '#biobio', '#araucania'], array_map(static fn ($n) => $n->nodeValue, iterator_to_array($x->query('//ul//a/@href'))));
    }

    public function test_unavailable_returns_the_same_three_unavailable_panels(): void
    {
        $expected = (new DetailRenderer())->render([]);
        DetailRenderer::resetInstances();

        $this->assertSame($expected, (new DetailRenderer())->unavailable());
    }

    // ---- cells, burned data, attribution -----------------------------------------------------

    public function test_missing_burned_data_reads_sin_dato_and_a_real_zero_reads_0_ha(): void
    {
        $d = $this->detail(
            'biobio',
            [self::row('a', 'ALTO', 1, null, self::FRESH, 'A'), self::row('b', 'ALTO', 1, null, self::FRESH, 'B'), self::row('c', 'ALTO', 1, null, self::FRESH, 'C')],
            self::burned(['a' => self::ha(0.0), 'b' => self::ha(null)]),
        );

        $x = $this->xpath($this->html([$d]));

        $cells = array_map(static fn ($n) => trim($n->textContent), iterator_to_array($x->query('//tbody/tr/td[4]')));
        $this->assertSame(['0' . self::NBSP . 'ha', 'Sin dato', 'Sin dato'], $cells);
        $this->assertSame('1 de 3 comunas con dato de superficie quemada', $this->text($x, '//p[contains(@class,"cat-detail__coverage")]'));
    }

    public function test_a_burned_failure_keeps_the_table_with_every_cell_sin_dato_and_no_year(): void
    {
        $d = $this->detail('biobio', null, DatasetResult::none());

        $html = $this->html([$d]);
        $x = $this->xpath($html);

        $this->assertSame(1, $x->query('//table')->length);
        $this->assertSame('Superficie quemada', $this->text($x, '//thead/tr/th[5]'));
        $this->assertSame(['Sin dato', 'Sin dato'], array_map(static fn ($n) => trim($n->textContent), iterator_to_array($x->query('//tbody/tr/td[4]'))));
        $this->assertSame('0 de 2 comunas con dato de superficie quemada', $this->text($x, '//p[contains(@class,"cat-detail__coverage")]'));
        $this->assertStringNotContainsString('MapBiomas', $html, 'nothing burned is shown, so nothing to cite');
        $this->assertStringContainsString('Índice de riesgo, no probabilidad de incendio', $html);
        $this->assertStringContainsString(Attribution::NOFIRES_URL, $html);
    }

    public function test_the_nofires_source_and_the_risk_note_show_whenever_a_table_renders(): void
    {
        $x = $this->xpath($this->html([$this->detail('biobio')]));

        $foot = $x->query('//footer[contains(@class,"cat-detail__foot")]');
        $this->assertSame(1, $foot->length);
        $this->assertStringContainsString('Índice de riesgo, no probabilidad de incendio', $foot->item(0)->textContent);
        $this->assertSame(Attribution::NOFIRES_URL, $x->query('.//a/@href', $foot->item(0))->item(0)?->nodeValue);
    }

    public function test_the_citation_shows_once_per_distinct_text_when_a_burned_value_is_displayed(): void
    {
        $a = $this->detail('nuble', null, self::burned(['nuble1' => self::ha(1.0)], 2025, 'Cita A'));
        $b = $this->detail('biobio', null, self::burned(['biobio1' => self::ha(1.0)], 2025, 'Cita A'));
        $c = $this->detail('araucania', null, self::burned(['araucania1' => self::ha(1.0)], 2025, 'Cita C'));

        $x = $this->xpath($this->html([$a, $b, $c]));

        $this->assertSame(['Superficie quemada: Cita A', 'Superficie quemada: Cita C'], array_map(static fn ($n) => trim($n->textContent), iterator_to_array($x->query('//footer//p[contains(@class,"cat-detail__cite")]'))));
        $this->assertSame(0, $x->query('//details | //footer//@hidden | //footer//@aria-hidden')->length);
    }

    public function test_the_default_citation_is_used_when_the_layer_omits_it(): void
    {
        $d = $this->detail('biobio', null, self::burned(['biobio1' => self::ha(1.0)], 2025, null));

        $html = $this->html([$d]);

        $this->assertSame(1, substr_count($html, Attribution::MAPBIOMAS_CITATION));
    }

    public function test_only_the_panels_that_show_a_burned_value_contribute_a_citation(): void
    {
        $shown = $this->detail('nuble', null, self::burned(['nuble1' => self::ha(1.0)], 2025, 'Cita visible'));
        $hidden = $this->detail('biobio', null, self::burned([], 2025, 'Cita oculta'));

        $html = $this->html([$shown, $hidden]);

        $this->assertStringContainsString('Cita visible', $html);
        $this->assertStringNotContainsString('Cita oculta', $html);
    }

    // ---- escaping, unknown level -------------------------------------------------------------

    public function test_every_dynamic_value_is_escaped(): void
    {
        $d = $this->detail(
            'biobio',
            [self::row('a', 'ALTO', 1, null, self::FRESH, '<script>alert(1)</script>'), self::row('b', '"><img src=x>', 1, null, self::FRESH, 'Rara')],
            self::burned(['a' => self::ha(1.0)], 2025, '<b>x</b> & "q"'),
        );

        $html = $this->html([$d]);
        $x = $this->xpath($html);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt; &amp; &quot;q&quot;', $html);
        $this->assertSame(0, $x->query('//script | //img | //b')->length);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_an_unknown_level_reads_sin_clasificar_sorts_last_and_has_no_cause(): void
    {
        $d = $this->detail('biobio', [self::row('a', 'EXTREMO', 3, 'FWI', self::FRESH, 'Zeta'), self::row('b', 'NORMAL', 1, 'FWI', self::FRESH, 'Beta')]);

        $html = $this->html([$d]);
        $x = $this->xpath($html);

        $this->assertSame(['Beta', 'Zeta'], array_map(static fn ($n) => trim($n->textContent), iterator_to_array($x->query('//tbody/tr/th'))));
        $this->assertSame('Sin clasificar', $this->text($x, '//tbody/tr[2]/td[1]'));
        $this->assertSame('—', $this->text($x, '//tbody/tr[2]/td[2]'));
        $this->assertStringNotContainsString('EXTREMO', $html);
    }

    public function test_the_level_is_text_and_the_swatch_is_decorative(): void
    {
        $d = $this->detail('biobio', [self::row('a', 'CRITICO', 1, 'FIRMS_TODAY', self::FRESH, 'A')]);

        $x = $this->xpath($this->html([$d]));

        $this->assertSame('Crítico', $this->text($x, '//tbody/tr/td[1]'));
        $this->assertSame('Foco de calor hoy', $this->text($x, '//tbody/tr/td[2]'));
        $this->assertSame(1, $x->query('//tbody/tr/td[1]/span[contains(@class,"cat-detail__sw--critico")][@aria-hidden="true"]')->length);
    }

    public function test_the_raw_driver_enum_never_reaches_the_page(): void
    {
        $html = $this->html($this->three());

        $this->assertStringNotContainsString('FWI', $html);
        $this->assertStringNotContainsString('FIRMS_', $html);
        $this->assertStringContainsString('Condiciones meteorológicas', $html);
    }

    // ---- hook --------------------------------------------------------------------------------

    public function test_the_hook_fires_once_per_panel_in_order_with_region_and_detail_even_when_unavailable(): void
    {
        $seen = [];
        Actions\expectDone(self::HOOK)->times(3)->with(\Mockery::type('string'), \Mockery::type(RegionDetail::class))
            ->whenHappen(static function (string $region, RegionDetail $d) use (&$seen): void {
                $seen[] = [$region, $d->region];
            });

        $this->html([$this->detail('nuble'), $this->unavailableDetail('biobio'), $this->detail('araucania')]);

        $this->assertSame([['nuble', 'nuble'], ['biobio', 'biobio'], ['araucania', 'araucania']], $seen);
    }

    public function test_without_a_listener_nothing_sits_between_the_summary_and_the_table(): void
    {
        $x = $this->xpath($this->html($this->three()));

        $this->assertSame(3, $x->query('//p[contains(@class,"cat-detail__coverage")]/following-sibling::*[1][self::div[@role="region"]]')->length);
        $this->assertSame(0, $x->query('//div[not(*) and not(normalize-space())]')->length, 'no empty wrapper');
    }

    public function test_listener_output_lands_between_the_summary_and_the_table(): void
    {
        Actions\expectDone(self::HOOK)->times(3)->whenHappen(static function (string $region): void {
            echo '<aside class="mine">' . $region . '</aside>';
        });

        $x = $this->xpath($this->html($this->three()));

        $this->assertSame(3, $x->query('//p[contains(@class,"cat-detail__coverage")]/following-sibling::*[1][self::div[@class="cat-detail__extra"]][aside[@class="mine"]]/following-sibling::*[1][self::div[@role="region"]]')->length);
    }

    public function test_a_throwing_listener_loses_only_its_own_output(): void
    {
        Actions\expectDone(self::HOOK)->times(3)->whenHappen(static function (string $region): void {
            if ($region === 'biobio') {
                echo '<aside class="partial">half';
                throw new \RuntimeException('listener exploded');
            }
        });
        $level = ob_get_level();

        $html = $this->html($this->three());
        $x = $this->xpath($html);

        $this->assertSame($level, ob_get_level(), 'output buffers are unwound');
        $this->assertSame(3, $x->query('//table')->length);
        $this->assertSame(1, $x->query('(//section[contains(@class,"cat-detail__panel")])[2]//table')->length);
        $this->assertSame(0, $x->query('//div[contains(@class,"cat-detail__extra")] | //aside')->length);
        $this->assertStringNotContainsString('half', $html);
    }

    public function test_unbalanced_listener_html_cannot_break_the_panel_structure(): void
    {
        Actions\expectDone(self::HOOK)->times(3)->whenHappen(static function (): void {
            echo '</section></div><div class="open"><p>unclosed';
        });

        $x = $this->xpath($this->html($this->three()));

        $this->assertSame(3, $x->query('//section[contains(@class,"cat-detail__panel")]')->length);
        foreach ([0, 1, 2] as $i) {
            $panel = $this->panel($x, $i);
            $this->assertSame(1, $x->query('./div[contains(@class,"cat-detail__extra")]/div[@class="open"]', $panel)->length);
            $this->assertSame(1, $x->query('./div[@role="region"]/table', $panel)->length, 'the table stays inside its panel');
        }
    }

    public function test_listener_scripts_are_stripped(): void
    {
        Actions\expectDone(self::HOOK)->times(3)->whenHappen(static function (): void {
            echo '<p class="ok">hi</p><script>alert(1)</script>';
        });

        $html = $this->html($this->three());

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertSame(3, $this->xpath($html)->query('//div[contains(@class,"cat-detail__extra")]/p[@class="ok"]')->length);
    }

    public function test_every_panel_carries_its_region_slug_whatever_its_id(): void
    {
        $renderer = new DetailRenderer();
        $renderer->render($this->three());
        $x = $this->xpath($renderer->render($this->three()));

        $this->assertSame(['nuble', 'biobio', 'araucania'], array_map(static fn ($n) => $n->nodeValue, iterator_to_array($x->query('//section[contains(@class,"cat-detail__panel")]/@data-region'))));
        $this->assertSame('biobio-2', $this->panel($x, 1)->attributes->getNamedItem('id')?->nodeValue);
    }

    public function test_a_failed_panel_fallback_also_carries_its_region_slug(): void
    {
        $x = $this->xpath((new DetailRenderer())->render(['not a detail']));

        $this->assertSame(['nuble', 'biobio', 'araucania'], array_map(static fn ($n) => $n->nodeValue, iterator_to_array($x->query('//section[contains(@class,"cat-detail__panel")]/@data-region'))));
    }

    public function test_a_throwing_renderer_input_degrades_every_panel(): void
    {
        $x = $this->xpath((new DetailRenderer())->render(['not a detail']));

        $this->assertSame(3, $x->query('//p[contains(@class,"cat-detail__empty")]')->length);
    }
}
