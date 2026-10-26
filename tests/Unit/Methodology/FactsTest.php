<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Methodology;

use CableATierra\Datos\Domain\AlertLevel;
use CableATierra\Datos\Domain\Attribution;
use CableATierra\Datos\Methodology\Facts;
use CableATierra\Datos\Summary\DriverCopy;
use CableATierra\Datos\Summary\RegionSummarizer;
use CableATierra\Datos\Wp\Cron;
use PHPUnit\Framework\TestCase;

/** Pins the methodology to the NoFires alert rules (api-semantics #906). */
final class FactsTest extends TestCase
{
    /**
     * DRIFT GUARD. These literals restate the NoFires rules on purpose (double entry): changing a
     * threshold in Facts without editing this test fails the build, so a change is a deliberate PR.
     */
    public function test_the_thresholds_are_pinned_to_the_nofires_rules(): void
    {
        $this->assertSame([
            'crit_fwi' => 45,
            'crit_score' => 0.85,
            'crit_firms' => 4,
            'crit_frp' => 60,
            'alto_fwi' => 20,
            'alto_score' => 0.70,
            'prev_score' => 0.50,
        ], Facts::THRESHOLDS);
    }

    public function test_the_levels_are_the_four_nofires_levels_in_first_match_order(): void
    {
        $levels = Facts::view()['levels'];

        $this->assertSame(['Crítico', 'Alto', 'Preventivo', 'Normal'], array_column($levels, 'name'));
        $this->assertSame(
            [AlertLevel::Critico->label(), AlertLevel::Alto->label(), AlertLevel::Preventivo->label(), AlertLevel::Normal->label()],
            array_column($levels, 'name'),
        );
    }

    public function test_the_rules_are_written_in_plain_spanish_with_es_cl_numbers(): void
    {
        $levels = Facts::view()['levels'];

        $this->assertSame([
            'Hay un foco de calor detectado hoy en la comuna (según la fecha de Chile).',
            'El índice meteorológico de incendios (FWI) es de 45 o más.',
            'El puntaje de riesgo es de 0,85 o más.',
            'Hubo 4 o más focos de calor en las últimas 48 horas.',
            'La intensidad media de los focos (potencia radiativa del fuego, FRP) es de 60 megavatios (MW) o más.',
        ], $levels[0]['items']);
        $this->assertSame([
            'El índice meteorológico de incendios (FWI) es de 20 o más.',
            'El puntaje de riesgo es de 0,70 o más.',
        ], $levels[1]['items']);
        $this->assertSame(['El puntaje de riesgo es de 0,50 o más.'], $levels[2]['items']);
        $this->assertSame([], $levels[3]['items']);
    }

    public function test_the_copy_never_uses_symbols_or_dot_decimals(): void
    {
        $text = json_encode(Facts::view(), JSON_UNESCAPED_UNICODE);

        $this->assertIsString($text);
        $this->assertDoesNotMatchRegularExpression('/[≥≤<>=]|\bFWI\s*[≥>]/u', $text);
        $this->assertDoesNotMatchRegularExpression('/\b0\.\d/', $text, 'decimals use a comma');
        $this->assertStringNotContainsString('BAJO', $text);
        $this->assertStringNotContainsString('MEDIO', $text);
    }

    public function test_the_intro_says_the_first_matching_rule_wins(): void
    {
        $this->assertStringContainsString('gana la primera', Facts::view()['intro']);
    }

    public function test_the_driver_glossary_has_the_five_drivers_with_the_table_labels(): void
    {
        $drivers = Facts::view()['drivers'];

        $this->assertCount(5, $drivers);
        $this->assertSame(
            array_map(static fn (string $d): ?string => DriverCopy::label($d, AlertLevel::Critico, false), ['FIRMS_TODAY', 'FIRMS_CLUSTER', 'FIRMS_FRP', 'FWI', 'SCORE']),
            array_column($drivers, 'label'),
        );
        $this->assertContains('Condiciones meteorológicas', array_column($drivers, 'label'));
        foreach ($drivers as $driver) {
            $this->assertNotSame('', trim($driver['meaning']));
        }
        $this->assertStringContainsString('solo', Facts::view()['driversNote']);
        $this->assertStringContainsString('Crítico y Alto', Facts::view()['driversNote']);
    }

    public function test_the_sources_name_the_exact_inputs(): void
    {
        $sources = implode("\n", Facts::view()['sources']);

        foreach (['NASA FIRMS, instrumento VIIRS del satélite NOAA-20', 'FWI', 'MapBiomas', 'NoFires', '48 horas', 'baja confianza', 'Condiciones meteorológicas: índice meteorológico de incendios (FWI)', 'Reportes ciudadanos: reportes enviados en la plataforma NoFires durante los últimos 7 días', 'todas las comunas de una región reciben el mismo valor'] as $needle) {
            $this->assertStringContainsString($needle, $sources);
        }
    }

    public function test_the_cadence_follows_the_cron_and_cache_constants(): void
    {
        $cadence = implode("\n", Facts::view()['cadence']);

        $this->assertSame(900, Cron::INTERVAL);
        $this->assertStringContainsString('NoFires recalcula el riesgo dos veces al día.', $cadence);
        $this->assertDoesNotMatchRegularExpression('/\d{2}:\d{2}|UTC/', $cadence);
        $this->assertStringContainsString('cada 15 minutos', $cadence);
        $this->assertStringContainsString('15 minutos y los de superficie quemada 24 horas', $cadence);
    }

    public function test_the_cadence_is_derived_not_typed(): void
    {
        $this->assertSame('15 minutos', Facts::duration(900));
        $this->assertSame('24 horas', Facts::duration(86400));
        $this->assertSame('1 hora', Facts::duration(3600));
        $this->assertSame('2 horas', Facts::duration(7200));
        $this->assertSame('1 minuto', Facts::duration(60));
    }

    public function test_the_limitations_cover_every_caveat(): void
    {
        $text = implode("\n", Facts::view()['limitations']);

        foreach ([
            'no probabilidad de incendio',
            'Copernicus',
            'solo con el tiempo, los focos de calor y los reportes ciudadanos',
            'UTC',
            'inferencia',
            'ventana móvil de 48 horas',
            'no es deforestación',
            'Sin dato',
            'desactualizados',
        ] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
    }

    public function test_the_attribution_uses_the_shared_constants(): void
    {
        $attribution = Facts::view()['attribution'];

        $this->assertSame(Attribution::NOFIRES_URL, $attribution['nofiresUrl']);
        $this->assertSame(Attribution::MAPBIOMAS_CITATION, $attribution['mapbiomas']);
        $this->assertStringContainsString('NASA FIRMS', $attribution['firms']);
    }

    public function test_the_score_sentence_is_plain_and_says_the_split_is_equal(): void
    {
        $view = Facts::view();

        $this->assertSame(
            'El puntaje de riesgo va de 0 a 1. Combina en partes iguales qué tan propenso es el territorio a incendiarse por su vegetación e historial de incendios (según MapBiomas) y las condiciones actuales: el tiempo, los focos de calor y los reportes ciudadanos recientes en la región.',
            $view['scoreNote'],
        );
        $this->assertStringNotContainsString('aproximadamente', $view['scoreNote']);
        $this->assertStringNotContainsString('susceptibilidad estructural', $view['scoreNote']);
        $this->assertStringContainsString('no depende solo del puntaje', $view['levelNote']);
        $this->assertStringContainsString('foco de calor detectado hoy', $view['levelNote']);
    }

    public function test_the_word_clima_is_never_used(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\bclima\b/iu', (string) json_encode(Facts::view(), JSON_UNESCAPED_UNICODE));
    }

    public function test_frp_is_given_in_megawatts_in_the_rule_and_the_glossary(): void
    {
        $view = Facts::view();

        $this->assertSame(60, Facts::THRESHOLDS['crit_frp']);
        $this->assertStringContainsString('es de 60 megavatios (MW) o más', $view['levels'][0]['items'][4]);
        $this->assertStringContainsString('de 60 megavatios (MW) o más', $view['drivers'][2]['meaning']);
    }

    public function test_the_copernicus_limitation_is_plain_and_dated(): void
    {
        $this->assertSame('2026-10-26', Facts::LIMITATIONS_CHECKED_ON);
        $text = implode("\n", Facts::view()['limitations']);

        $this->assertStringContainsString('Al 26 de octubre de 2026, NoFires no cuenta con los índices de vegetación y humedad que se obtienen desde satélite (Copernicus), así que calcula solo con el tiempo, los focos de calor y los reportes ciudadanos.', $text);
        $this->assertStringNotContainsString('STANDARD', $text);
        $this->assertStringNotContainsString('NDVI', $text);
    }

    /**
     * MAINTENANCE GUARD. Uses the real date on purpose: an injected clock would never expire, and the
     * point is to fail once the limitation has gone unchecked for six months.
     */
    public function test_the_dated_limitation_expires_six_months_after_it_was_checked(): void
    {
        $expires = (new \DateTimeImmutable(Facts::LIMITATIONS_CHECKED_ON . ' UTC'))->modify('+6 months');

        $this->assertLessThanOrEqual(
            $expires->getTimestamp(),
            time(),
            'The Copernicus limitation was last verified on ' . Facts::LIMITATIONS_CHECKED_ON . '. Re-verify with NoFires whether it still applies, then bump Facts::LIMITATIONS_CHECKED_ON.',
        );
    }

    public function test_the_staleness_thresholds_come_from_the_summarizer_constants(): void
    {
        $this->assertSame(86400, RegionSummarizer::OK_MAX_AGE);
        $this->assertSame(172800, RegionSummarizer::STALE_MAX_AGE);
        $text = implode("\n", Facts::view()['limitations']);

        $this->assertStringContainsString('Si los datos tienen más de 24 horas, la página avisa que están desactualizados. Si tienen más de 48 horas, los focos se muestran como «48 h previas al» día del último dato.', $text);
    }

    public function test_the_hotspot_window_is_pinned_and_used_everywhere(): void
    {
        $text = (string) json_encode(Facts::view(), JSON_UNESCAPED_UNICODE);

        $this->assertSame(48, Facts::FIRMS_WINDOW_HOURS);
        $this->assertSame(4, preg_match_all('/últimas 48 horas|ventana móvil de 48 horas/u', $text));
    }
}
