<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Render;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use CableATierra\Datos\Data\DatasetResult;
use CableATierra\Datos\Render\BandRenderer;
use CableATierra\Datos\Summary\RegionSummarizer;
use CableATierra\Datos\Summary\RegionSummary;
use CableATierra\Datos\Tests\Support\SummaryFixtures;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BandRendererTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use SummaryFixtures;

    private const FRESH = '2026-10-25T10:00:00Z';
    private const HOURS_30 = '2026-10-24T06:00:00Z';
    private const HOURS_50 = '2026-10-23T10:00:00Z';
    private const HOURS_24 = '2026-10-24T12:00:00Z';
    private const HOURS_48 = '2026-10-23T12:00:00Z';
    private const NBSP = "\u{00A0}";

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\stubEscapeFunctions();
        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'https://site.test' . $path);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ---- builders -------------------------------------------------------------------------

    /** @param list<array<string, mixed>>|null $rows */
    private function region(string $region, ?array $rows = null, ?DatasetResult $burned = null): RegionSummary
    {
        $rows ??= [self::row($region . '1', 'ALTO', 2, null, self::FRESH), self::row($region . '2', 'NORMAL', 1, null, self::FRESH)];

        return (new RegionSummarizer())->summarize(
            $region,
            $rows === [] ? DatasetResult::none() : self::risk($rows),
            $burned ?? self::burned(['a' => self::ha(1000.0), 'b' => self::ha(234.4)]),
            self::now(),
        );
    }

    /** @return list<RegionSummary> */
    private function fresh(): array
    {
        return [$this->region('nuble'), $this->region('biobio'), $this->region('araucania')];
    }

    /** @param list<RegionSummary> $summaries */
    private function html(array $summaries): string
    {
        return (new BandRenderer())->render($summaries, self::now());
    }

    /** Parses as XML: proves the output is well formed and correctly nested. */
    private function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($html);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        self::assertTrue($ok, 'Output is not well-formed: ' . ($errors[0]->message ?? '') . "\n" . $html);

        return new \DOMXPath($doc);
    }

    private function text(\DOMXPath $x, string $query, ?\DOMNode $ctx = null): string
    {
        $nodes = $ctx === null ? $x->query($query) : $x->query($query, $ctx);
        self::assertNotFalse($nodes);

        return trim((string) preg_replace('/[ \t\r\n]+/', ' ', $nodes->length > 0 ? (string) $nodes->item(0)?->textContent : ''));
    }

    private function card(\DOMXPath $x, int $i): \DOMNode
    {
        $card = $x->query('//article')->item($i);
        self::assertNotNull($card);

        return $card;
    }

    // ---- structure, order, numbers ----------------------------------------------------------

    public function test_renders_the_whole_section_with_three_cards_in_fixed_order(): void
    {
        // Input order must not matter.
        $x = $this->xpath($this->html([$this->region('araucania'), $this->region('nuble'), $this->region('biobio')]));

        self::assertSame(1, $x->query('//section[@id="datos"][contains(@class,"cat-data")][contains(@class,"cat-band")]')->length);
        $names = [];
        foreach ($x->query('//article/header/h3') as $h3) {
            $names[] = $h3->textContent;
        }
        self::assertSame(['Ñuble', 'Biobío', 'La Araucanía'], $names);
        self::assertSame('El territorio, medido.', $this->text($x, '//h2'));
        self::assertStringContainsString('Datos', $this->text($x, '//p[contains(@class,"cat-kicker")]'));
    }

    public function test_card_shows_firms_distribution_with_zeros_and_burned(): void
    {
        $rows = [self::row('1', 'CRITICO', 1234, 'FIRMS_TODAY', self::FRESH, 'Yungay'), self::row('2', 'ALTO', 20, null, self::FRESH)];
        $x = $this->xpath($this->html([$this->region('nuble', $rows), $this->region('biobio'), $this->region('araucania')]));
        $card = $this->card($x, 0);

        self::assertSame('Focos detectados, últimas 48 h 1.254', $this->text($x, './/dl[1]', $card));
        self::assertSame('Crítico 1 comunas', $this->text($x, './/ul[contains(@class,"cat-legend")]/li[1]', $card));
        self::assertSame('Alto 1 comunas', $this->text($x, './/ul[contains(@class,"cat-legend")]/li[2]', $card));
        self::assertSame('Preventivo 0 comunas', $this->text($x, './/ul[contains(@class,"cat-legend")]/li[3]', $card), 'zeros are shown');
        self::assertSame('Normal 0 comunas', $this->text($x, './/ul[contains(@class,"cat-legend")]/li[4]', $card));
        self::assertSame('Superficie quemada, 2025 1.234' . self::NBSP . 'ha', $this->text($x, './/dl[2]', $card));
    }

    public function test_bar_is_decorative_with_one_segment_per_level_and_no_other_inline_style(): void
    {
        $html = $this->html($this->fresh());
        $x = $this->xpath($html);

        self::assertSame(3, $x->query('//div[contains(@class,"cat-bar")][@aria-hidden="true"]')->length);
        $segs = $x->query('//article[1]//div[contains(@class,"cat-bar")]/span');
        self::assertSame(4, $segs->length);
        self::assertSame('--n:0', $x->query('./@style', $segs->item(2))->item(0)?->nodeValue, 'zero segments are emitted with --n:0');
        self::assertStringContainsString('cat-level--critico', (string) $segs->item(0)?->getAttribute('class'));
        self::assertSame(0, $x->query('//*[@style][not(contains(@class,"cat-bar__seg"))]')->length);
        self::assertStringNotContainsString('<script', $html);
        self::assertSame(0, $x->query('//@*[starts-with(name(), "on")]')->length, 'no inline handlers');
    }

    public function test_unknown_level_is_not_a_bar_segment_and_counts_stay_classified(): void
    {
        $rows = [self::row('1', 'ALTO', 0, null, self::FRESH), self::row('2', 'EXTREMO', 0, null, self::FRESH), self::row('3', 'NORMAL', 0, null, self::FRESH)];
        $x = $this->xpath($this->html([$this->region('nuble', $rows), $this->region('biobio'), $this->region('araucania')]));
        $card = $this->card($x, 0);

        self::assertSame(4, $x->query('.//div[contains(@class,"cat-bar")]/span', $card)->length);
        self::assertStringContainsString('(2)', $this->text($x, './/p[contains(@class,"cat-region__dist-title")]', $card));
        self::assertStringNotContainsString('EXTREMO', $x->document->textContent ?? '');
        self::assertStringNotContainsString('UNKNOWN', $x->document->textContent ?? '');
    }

    public function test_no_raw_api_enums_reach_the_reader(): void
    {
        $text = $this->xpath($this->html($this->fresh()))->document->textContent ?? '';

        foreach (['CRITICO', 'ALTO', 'PREVENTIVO', 'NORMAL', 'FIRMS_', 'nuble', 'biobio', 'araucania'] as $raw) {
            self::assertStringNotContainsString($raw, $text);
        }
    }

    // ---- headline -------------------------------------------------------------------------

    public function test_headline_names_critical_comunas_with_plain_language_driver(): void
    {
        $rows = [self::row('1', 'CRITICO', 3, 'FIRMS_TODAY', self::FRESH, 'Lonquimay')];
        $x = $this->xpath($this->html([$this->region('nuble'), $this->region('biobio'), $this->region('araucania', $rows)]));

        self::assertSame(
            '1 comuna en nivel crítico: Lonquimay (La Araucanía), por un foco de calor detectado hoy.',
            $this->text($x, '//p[contains(@class,"cat-band__summary")]'),
        );
    }

    public function test_headline_states_when_no_comuna_is_critical(): void
    {
        $x = $this->xpath($this->html($this->fresh()));

        self::assertStringStartsWith('Ninguna comuna en nivel crítico.', $this->text($x, '//p[contains(@class,"cat-band__summary")]'));
    }

    // ---- staleness ------------------------------------------------------------------------

    public function test_fresh_card_has_no_chip(): void
    {
        $x = $this->xpath($this->html($this->fresh()));

        self::assertSame(0, $x->query('//*[contains(@class,"cat-region__flag")]')->length);
        self::assertStringNotContainsString('Último dato', $x->document->textContent ?? '');
    }

    public function test_stale_card_shows_chip_and_last_data_time_but_keeps_the_label(): void
    {
        $x = $this->xpath($this->html([$this->region('nuble', [self::row('1', 'ALTO', 5, null, self::HOURS_30)]), $this->region('biobio'), $this->region('araucania')]));
        $card = $this->card($x, 0);

        self::assertSame('Datos desactualizados', $this->text($x, './/p[contains(@class,"cat-region__flag")]', $card));
        self::assertSame('Último dato: 24 oct, 03:00', $this->text($x, './/p[contains(@class,"cat-region__asof")]', $card));
        self::assertStringContainsString('Focos detectados, últimas 48 h', $card->textContent);
        self::assertSame(0, $x->query('.//*[contains(@class,"cat-region__flag")]', $this->card($x, 1))->length);
    }

    public function test_very_stale_card_relabels_the_firms_count(): void
    {
        $x = $this->xpath($this->html([$this->region('nuble', [self::row('1', 'ALTO', 5, null, self::HOURS_50)]), $this->region('biobio'), $this->region('araucania')]));
        $card = $this->card($x, 0);

        self::assertSame('Datos desactualizados', $this->text($x, './/p[contains(@class,"cat-region__flag")]', $card));
        self::assertStringContainsString('Focos, 48 h previas al 23 oct', $card->textContent);
        self::assertStringNotContainsString('últimas 48 h', $card->textContent);
        self::assertStringContainsString('Focos detectados, últimas 48 h', $this->card($x, 1)->textContent);
    }

    /** @return array<string, array{string, bool}> */
    public static function boundaries(): array
    {
        return [
            'exactly 24 h is fresh' => [self::HOURS_24, false],
            'exactly 48 h is stale' => [self::HOURS_48, true],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_boundary_ages(string $computedAt, bool $chip): void
    {
        $x = $this->xpath($this->html([$this->region('nuble', [self::row('1', 'ALTO', 5, null, $computedAt)]), $this->region('biobio'), $this->region('araucania')]));
        $card = $this->card($x, 0);

        self::assertSame($chip ? 1 : 0, $x->query('.//*[contains(@class,"cat-region__flag")]', $card)->length);
        self::assertStringContainsString('últimas 48 h', $card->textContent);
    }

    // ---- unavailable states ------------------------------------------------------------------

    public function test_one_unavailable_region_keeps_its_card_without_stats(): void
    {
        $x = $this->xpath($this->html([$this->region('nuble'), $this->region('biobio', []), $this->region('araucania')]));
        $card = $this->card($x, 1);

        self::assertSame(3, $x->query('//article')->length);
        self::assertSame('Biobío', $this->text($x, './header/h3', $card));
        self::assertSame('Datos no disponibles por el momento', $this->text($x, './/p[contains(@class,"cat-region__empty")]', $card));
        self::assertSame(0, $x->query('.//dl|.//ul|.//div[contains(@class,"cat-bar")]', $card)->length);
        self::assertStringNotContainsString('0', preg_replace('/Biobío/u', '', $card->textContent) ?? '');
        self::assertSame(2, $x->query('//article[.//dl]')->length);
    }

    public function test_all_unavailable_renders_only_heading_message_and_cta(): void
    {
        $x = $this->xpath($this->html([$this->region('nuble', []), $this->region('biobio', []), $this->region('araucania', [])]));

        self::assertSame('El territorio, medido.', $this->text($x, '//h2'));
        self::assertSame('Datos no disponibles por el momento', $this->text($x, '//p[contains(@class,"cat-band__empty")]'));
        self::assertSame('Explorar los datos', $this->text($x, '//a[contains(@class,"wp-block-button__link")]'));
        self::assertSame(0, $x->query('//article|//footer|//dl|//*[contains(@class,"cat-band__summary")]')->length);
        self::assertSame(1, $x->query('//section[@id="datos"]')->length);
    }

    public function test_missing_summaries_count_as_unavailable(): void
    {
        $x = $this->xpath($this->html([]));

        self::assertSame(1, $x->query('//p[contains(@class,"cat-band__empty")]')->length);
        self::assertSame(0, $x->query('//article')->length);
    }

    public function test_burned_unavailable_while_risk_is_ok_never_shows_zero(): void
    {
        $none = DatasetResult::none();
        $x = $this->xpath($this->html([$this->region('nuble', null, $none), $this->region('biobio'), $this->region('araucania')]));
        $card = $this->card($x, 0);

        self::assertSame('Superficie quemada — Dato no disponible', $this->text($x, './/dl[2]', $card));
        self::assertStringNotContainsString('0' . self::NBSP . 'ha', $card->textContent);
        self::assertStringContainsString('Focos detectados', $card->textContent);
        self::assertSame(4, $x->query('.//ul[contains(@class,"cat-legend")]/li', $card)->length);
    }

    public function test_layer_with_no_available_comuna_is_unavailable_not_zero(): void
    {
        $burned = self::burned(['a' => self::ha(null), 'b' => self::ha(null)]);
        $x = $this->xpath($this->html([$this->region('nuble', null, $burned), $this->region('biobio'), $this->region('araucania')]));

        self::assertStringContainsString('Dato no disponible', $this->card($x, 0)->textContent);
        self::assertStringNotContainsString('0' . self::NBSP . 'ha', $this->card($x, 0)->textContent);
    }

    public function test_partial_burned_coverage_is_inline(): void
    {
        $values = [];
        for ($i = 1; $i <= 33; $i++) {
            $values['c' . $i] = self::ha($i <= 30 ? 411.3333 : null);
        }
        $x = $this->xpath($this->html([$this->region('nuble', null, self::burned($values)), $this->region('biobio'), $this->region('araucania')]));

        self::assertSame('Superficie quemada, 2025 12.340' . self::NBSP . 'ha (30 de 33 comunas)', $this->text($x, './/dl[2]', $this->card($x, 0)));
    }

    // ---- attribution ------------------------------------------------------------------------

    public function test_citation_is_fully_visible_in_the_footer_and_not_collapsed(): void
    {
        $citation = 'MapBiomas Chile (2025). Colección de prueba. https://example.test/cita';
        $burned = self::burned(['a' => self::ha(10.0)], 2025, $citation);
        $x = $this->xpath($this->html([$this->region('nuble', null, $burned), $this->region('biobio', null, $burned), $this->region('araucania', null, $burned)]));

        self::assertSame(1, substr_count($x->document->textContent ?? '', $citation), 'identical citations are deduplicated');
        self::assertSame(0, $x->query('//details|//summary')->length);
        self::assertSame(1, $x->query('//footer//*[contains(., "' . $citation . '")][not(*)]')->length);
    }

    public function test_distinct_citations_are_all_shown(): void
    {
        $a = self::burned(['a' => self::ha(10.0)], 2025, 'Cita A');
        $b = self::burned(['a' => self::ha(10.0)], 2025, 'Cita B');
        $text = $this->xpath($this->html([$this->region('nuble', null, $a), $this->region('biobio', null, $b), $this->region('araucania', null, $a)]))->document->textContent ?? '';

        self::assertStringContainsString('Cita A', $text);
        self::assertStringContainsString('Cita B', $text);
    }

    /** @return array<string, array{callable(self): list<RegionSummary>}> */
    public static function scenarios(): array
    {
        return [
            'all fresh' => [static fn (self $t): array => $t->fresh()],
            'layer without citation' => [static fn (self $t): array => [$t->region('nuble', null, self::burned(['a' => self::ha(1.0)], 2025, null)), $t->region('biobio'), $t->region('araucania')]],
            'burned missing everywhere' => [static fn (self $t): array => [$t->region('nuble', null, DatasetResult::none()), $t->region('biobio', null, DatasetResult::none()), $t->region('araucania', null, DatasetResult::none())]],
            'one unavailable' => [static fn (self $t): array => [$t->region('nuble', []), $t->region('biobio'), $t->region('araucania')]],
            'very stale' => [static fn (self $t): array => [$t->region('nuble', [self::row('1', 'ALTO', 1, null, self::HOURS_50)]), $t->region('biobio'), $t->region('araucania')]],
        ];
    }

    /** @param callable(self): list<RegionSummary> $make */
    #[DataProvider('scenarios')]
    public function test_whenever_burned_area_is_rendered_a_citation_is_present(callable $make): void
    {
        $html = $this->html($make($this));

        self::assertStringContainsString('Superficie quemada', $html);
        $footer = $this->text($this->xpath($html), '//footer');
        self::assertStringContainsString('MapBiomas', $footer);
    }

    public function test_default_citation_is_used_when_the_layer_has_none(): void
    {
        $burned = self::burned(['a' => self::ha(1.0)], 2025, null);
        $text = $this->text($this->xpath($this->html([$this->region('nuble', null, $burned), $this->region('biobio', null, $burned), $this->region('araucania', null, $burned)])), '//footer');

        self::assertStringContainsString(BandRenderer::DEFAULT_CITATION, $text);
    }

    public function test_footer_shows_oldest_update_time_and_sources(): void
    {
        $oldest = [self::row('1', 'ALTO', 1, null, '2026-10-25T01:30:00Z'), self::row('2', 'ALTO', 1, null, self::FRESH)];
        $x = $this->xpath($this->html([$this->region('nuble'), $this->region('biobio', $oldest), $this->region('araucania', [])]));
        $foot = $this->text($x, '//footer');

        self::assertStringContainsString('Actualizado el 24 de octubre de 2026, 22:30 (hora de Chile).', $foot);
        self::assertStringContainsString('Fuente: NoFires (nofires-latam.vercel.app).', $foot);
        self::assertStringContainsString('Focos: detecciones satelitales de calor (NASA FIRMS).', $foot);
        self::assertSame('https://nofires-latam.vercel.app', $x->query('//footer//a')->item(0)?->attributes?->getNamedItem('href')?->nodeValue);
    }

    // ---- note, link, call to action ----------------------------------------------------------

    public function test_method_note_is_always_present_and_link_only_with_a_url(): void
    {
        $x = $this->xpath($this->html($this->fresh()));
        self::assertSame('Índice de riesgo, no probabilidad de incendio.', $this->text($x, '//p[contains(@class,"cat-band__note")]'));
        self::assertSame(0, $x->query('//p[contains(@class,"cat-band__note")]//a')->length);

        Filters\expectApplied('cat_datos_methodology_url')->once()->andReturn('https://site.test/metodologia/');
        $x = $this->xpath($this->html($this->fresh()));
        self::assertSame('Índice de riesgo, no probabilidad de incendio. Cómo se calcula', $this->text($x, '//p[contains(@class,"cat-band__note")]'));
        self::assertSame('https://site.test/metodologia/', $x->query('//p[contains(@class,"cat-band__note")]//a/@href')->item(0)?->nodeValue);
    }

    public function test_cta_defaults_to_the_data_page_and_can_be_removed_by_filter(): void
    {
        $x = $this->xpath($this->html($this->fresh()));
        self::assertSame('https://site.test/datos/', $x->query('//a[contains(@class,"wp-block-button__link")]/@href')->item(0)?->nodeValue);

        Filters\expectApplied('cat_datos_explore_url')->once()->andReturn('');
        self::assertSame(0, $this->xpath($this->html($this->fresh()))->query('//a[contains(@class,"wp-block-button__link")]')->length);
    }

    // ---- escaping ---------------------------------------------------------------------------

    public function test_hostile_values_are_rendered_inert(): void
    {
        $rows = [self::row('1', 'CRITICO', 1, 'FIRMS_TODAY', self::FRESH, '<script>alert(1)</script>')];
        $citation = '<b>x</b> & "q"';
        $burned = self::burned(['a' => self::ha(5.0)], 2025, $citation);
        // Brain Monkey's esc_url stub keeps quotes and angle brackets; real esc_url() strips them.
        Functions\when('esc_url')->alias(static fn (string $u): string => htmlspecialchars((string) preg_replace('/[<>"\s]/', '', $u), ENT_QUOTES));
        Filters\expectApplied('cat_datos_methodology_url')->andReturn('https://x.test/?a="><script>alert(2)</script>');
        Filters\expectApplied('cat_datos_explore_url')->andReturn('https://x.test/?b="><img src=x onerror=alert(3)>');

        $html = $this->html([$this->region('nuble', $rows, $burned), $this->region('biobio', null, $burned), $this->region('araucania', null, $burned)]);

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<b>x</b>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt; &amp; &quot;q&quot;', $html);
        $x = $this->xpath($html);
        self::assertSame(0, $x->query('//script|//img|//b')->length);
        self::assertStringContainsString($citation, $this->text($x, '//footer'));
    }

    // ---- accessibility and semantics ----------------------------------------------------------

    public function test_landmarks_headings_and_relations_are_consistent(): void
    {
        $x = $this->xpath($this->html($this->fresh()));

        $labelledBy = $x->query('//section/@aria-labelledby')->item(0)?->nodeValue;
        self::assertSame(1, $x->query('//h2[@id="' . $labelledBy . '"]')->length);
        self::assertSame(1, $x->query('//h2')->length);
        self::assertSame(3, $x->query('//h3')->length);
        self::assertSame(0, $x->query('//h1|//h4|//h5|//h6')->length);
        foreach ($x->query('//article') as $article) {
            $id = $article->attributes?->getNamedItem('aria-labelledby')?->nodeValue;
            self::assertSame(1, $x->query('.//h3[@id="' . $id . '"]', $article)->length);
        }
        foreach ($x->query('//ul[contains(@class,"cat-legend")]') as $ul) {
            $id = $ul->attributes?->getNamedItem('aria-labelledby')?->nodeValue;
            self::assertSame(1, $x->query('//p[@id="' . $id . '"]')->length);
        }
        $ids = [];
        foreach ($x->query('//@id') as $id) {
            $ids[] = $id->nodeValue;
        }
        self::assertSame($ids, array_values(array_unique($ids)), 'ids are unique');
        self::assertSame(6, $x->query('//dl/div/dt')->length);
        self::assertSame(0, $x->query('//p//div|//p//p|//p//ul|//p//dl')->length);
        self::assertSame(0, $x->query('//*[@lang]|//script|//style|//*[@onclick]')->length);
    }

    public function test_stale_notice_is_text_not_only_a_class(): void
    {
        $x = $this->xpath($this->html([$this->region('nuble', [self::row('1', 'ALTO', 5, null, self::HOURS_30)]), $this->region('biobio'), $this->region('araucania')]));

        self::assertSame('Datos desactualizados', $this->text($x, '//p[contains(@class,"cat-region__flag")]'));
    }

    // ---- fault isolation --------------------------------------------------------------------

    public function test_a_failing_render_returns_the_unavailable_markup(): void
    {
        /** @phpstan-ignore-next-line deliberately wrong input */
        $html = (new BandRenderer())->render([new \stdClass()], self::now());

        $x = $this->xpath($html);
        self::assertSame('Datos no disponibles por el momento', $this->text($x, '//p[contains(@class,"cat-band__empty")]'));
        self::assertSame(0, $x->query('//article')->length);
    }

    public function test_even_a_missing_template_directory_yields_the_unavailable_message(): void
    {
        $html = (new BandRenderer('/nonexistent/templates'))->render($this->fresh(), self::now());

        $x = $this->xpath($html);
        self::assertSame('Datos no disponibles por el momento', $this->text($x, '//p'));
        self::assertSame('datos', $x->query('//section/@id')->item(0)?->nodeValue);
    }

    public function test_output_buffers_are_balanced_after_a_failure(): void
    {
        $level = ob_get_level();
        (new BandRenderer())->render([new \stdClass()], self::now());

        self::assertSame($level, ob_get_level());
    }
}
