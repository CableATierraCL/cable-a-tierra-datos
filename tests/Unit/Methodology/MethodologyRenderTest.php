<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Methodology;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CableATierra\Datos\Domain\Attribution;
use CableATierra\Datos\Methodology\Facts;
use CableATierra\Datos\Render\Templates;
use CableATierra\Datos\Tests\Support\DetailHtml;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class MethodologyRenderTest extends TestCase
{
    use DetailHtml;
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubEscapers();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @param array<string, mixed> $view */
    private function html(?array $view = null): string
    {
        return (new Templates())->render('methodology', $view ?? Facts::view());
    }

    public function test_it_is_a_labelled_section_with_a_stable_id_and_no_page_title(): void
    {
        $x = $this->xpath($this->html());

        $this->assertSame(1, $x->query('//section[@id="metodologia"][@aria-labelledby="metodologia-title"]')->length);
        $this->assertSame('Cómo se calcula', $this->text($x, '//h2[@id="metodologia-title"]'));
        $this->assertSame(0, $x->query('//h1')->length);
        $this->assertSame(1, $x->query('//section[contains(@class,"alignwide")]')->length);
    }

    public function test_the_levels_come_in_severity_order_with_plain_language_numbers(): void
    {
        $x = $this->xpath($this->html());

        $names = [];
        foreach ($x->query('//ol[contains(@class,"cat-detail__levels")]/li/strong') as $strong) {
            $names[] = trim($strong->textContent);
        }
        $this->assertSame(['Crítico', 'Alto', 'Preventivo', 'Normal'], $names);

        $levels = $x->query('//ol[contains(@class,"cat-detail__levels")]/li');
        $this->assertSame(4, $levels->length);
        $critico = $this->text($x, '.', $levels->item(0));
        $alto = $this->text($x, '.', $levels->item(1));
        $preventivo = $this->text($x, '.', $levels->item(2));
        foreach (['índice meteorológico de incendios (FWI) es de 45 o más', 'puntaje de riesgo es de 0,85 o más', '4 o más focos', 'es de 60 o más'] as $needle) {
            $this->assertStringContainsString($needle, $critico);
        }
        foreach (['(FWI) es de 20 o más', 'es de 0,70 o más'] as $needle) {
            $this->assertStringContainsString($needle, $alto);
        }
        $this->assertStringContainsString('es de 0,50 o más', $preventivo);
        $this->assertStringNotContainsString('0,85', $alto);
        $this->assertStringNotContainsString('45', $alto);
    }

    public function test_no_symbols_and_no_invented_levels(): void
    {
        $html = $this->html();

        $this->assertDoesNotMatchRegularExpression('/FWI\s*(≥|&gt;=|&ge;|>=)/u', $html);
        $this->assertStringNotContainsString('BAJO', $html);
        $this->assertStringNotContainsString('MEDIO', $html);
        $this->assertStringContainsString('gana la primera', $html);
    }

    public function test_the_glossary_lists_the_five_drivers_and_says_cause_is_for_critico_and_alto(): void
    {
        $x = $this->xpath($this->html());

        $terms = [];
        foreach ($x->query('//dl[contains(@class,"cat-detail__terms")]/dt') as $dt) {
            $terms[] = trim($dt->textContent);
        }
        $this->assertSame(['Foco de calor', 'Varios focos', 'Focos intensos', 'Condiciones meteorológicas', 'Puntaje alto'], $terms);
        $this->assertSame(5, $x->query('//dl[contains(@class,"cat-detail__terms")]/dd')->length);
        $this->assertStringContainsString('Crítico y Alto', $this->text($x, '//section[@id="metodologia"]'));
    }

    public function test_sources_cadence_and_limitations_are_present(): void
    {
        $text = $this->text($this->xpath($this->html()), '//section[@id="metodologia"]');

        foreach (['VIIRS_NOAA20_NRT', 'MapBiomas', 'NoFires', '01:30', '13:30', 'cada 15 minutos', 'STANDARD', 'Copernicus', 'inferencia', 'ventana móvil de 48 horas', 'no es deforestación', 'no probabilidad de incendio'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
    }

    public function test_the_full_citation_and_nofires_credit_are_visible(): void
    {
        $x = $this->xpath($this->html());

        $this->assertStringContainsString(Attribution::MAPBIOMAS_CITATION, $this->text($x, '//section[@id="metodologia"]'));
        $this->assertSame(0, $x->query('//details | //*[@hidden] | //*[@aria-hidden="true"][not(contains(@class,"cat-detail__sw"))]')->length);
        $this->assertSame(1, $x->query('//a[@href="' . Attribution::NOFIRES_URL . '"]')->length);
    }

    public function test_dynamic_text_is_entity_escaped(): void
    {
        $view = Facts::view();
        $view['attribution']['mapbiomas'] = 'A & "q" <b>x</b>';
        $view['levels'][0]['name'] = '<script>alert(1)</script>';

        $html = $this->html($view);

        $this->assertStringContainsString('A &amp; &quot;q&quot; &lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function test_it_reads_nothing_and_needs_no_wordpress_data_functions(): void
    {
        foreach (['get_option', 'get_transient', 'set_transient', 'wp_remote_get', 'wp_cache_get'] as $function) {
            Functions\expect($function)->never();
        }

        $this->assertNotSame('', $this->html());
    }
}
