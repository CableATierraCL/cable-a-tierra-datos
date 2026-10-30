<?php

declare(strict_types=1);

namespace CableATierra\Datos\Methodology;

use CableATierra\Datos\Domain\AlertLevel;
use CableATierra\Datos\Domain\Attribution;
use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Format\SpanishDate;
use CableATierra\Datos\Summary\DriverCopy;
use CableATierra\Datos\Summary\RegionSummarizer;
use CableATierra\Datos\Wp\Cron;

/**
 * Everything the methodology section says, in one pure place: the NoFires alert rules (pinned by
 * FactsTest, a deliberate-edit drift guard), the driver glossary (labels come from DriverCopy), the
 * sources, the refresh cadence (derived from the cache and cron constants) and the limitations.
 * It reads no repository, cache or network. Copy is plain Spanish for a general audience: numbers
 * inside sentences, never symbols; the template only escapes and lays it out.
 */
final class Facts
{
    /** NoFires rules, first match wins (api-semantics): CRITICO, then ALTO, then PREVENTIVO, else NORMAL. */
    public const THRESHOLDS = [
        'crit_fwi' => 45,
        'crit_score' => 0.85,
        'crit_firms' => 4,
        'crit_frp' => 60,
        'alto_fwi' => 20,
        'alto_score' => 0.70,
        'prev_score' => 0.50,
    ];

    /** Hotspot window NoFires uses (hours). Mirrors NoFires; the band and detail copy do not read it. */
    public const FIRMS_WINDOW_HOURS = 48;

    /**
     * Date (Y-m-d) on which the Copernicus limitation was last confirmed with NoFires. FactsTest fails
     * six months after it, so a maintainer re-verifies with NoFires and bumps it.
     */
    public const LIMITATIONS_CHECKED_ON = '2026-10-26';

    private const T = self::THRESHOLDS;

    /** @return array<string, mixed> Plain text only; escaping happens in the template. */
    public static function view(): array
    {
        $fwi = 'El índice meteorológico de incendios (FWI) es de %d o más.';
        $score = 'El puntaje de riesgo es de %s o más.';
        $risk = self::duration(Dataset::Risk->ttl());
        $burned = self::duration(Dataset::Burned->ttl());
        $window = self::FIRMS_WINDOW_HOURS;
        $checked = SpanishDate::date(new \DateTimeImmutable(self::LIMITATIONS_CHECKED_ON));

        return [
            'title' => 'Cómo se calcula',
            'intro' => 'NoFires calcula el nivel de alerta de cada comuna con las reglas de más abajo. Se revisan en orden, de la más grave a la menos grave, y gana la primera que se cumple.',
            'levels' => [
                ['name' => AlertLevel::Critico->label(), 'lead' => 'Se cumple al menos una de estas condiciones:', 'items' => [
                    'Hay un foco de calor detectado hoy en la comuna (según la fecha de Chile).',
                    sprintf($fwi, self::T['crit_fwi']),
                    sprintf($score, self::decimal(self::T['crit_score'])),
                    sprintf('Hubo %d o más focos de calor en las últimas %d horas.', self::T['crit_firms'], $window),
                    sprintf('La intensidad media de los focos (potencia radiativa del fuego, FRP) es de %d megavatios (MW) o más.', self::T['crit_frp']),
                ]],
                ['name' => AlertLevel::Alto->label(), 'lead' => 'No es crítico y se cumple al menos una de estas condiciones:', 'items' => [
                    sprintf($fwi, self::T['alto_fwi']),
                    sprintf($score, self::decimal(self::T['alto_score'])),
                ]],
                ['name' => AlertLevel::Preventivo->label(), 'lead' => 'No es crítico ni alto y se cumple esta condición:', 'items' => [
                    sprintf($score, self::decimal(self::T['prev_score'])),
                ]],
                ['name' => AlertLevel::Normal->label(), 'lead' => 'No se cumple ninguna de las condiciones anteriores.', 'items' => []],
            ],
            'scoreNote' => 'El puntaje de riesgo va de 0 a 1. Combina en partes iguales qué tan propenso es el territorio a incendiarse por su vegetación e historial de incendios (según MapBiomas) y las condiciones actuales: el tiempo, los focos de calor y los reportes ciudadanos recientes en la región.',
            'levelNote' => 'El nivel de alerta no depende solo del puntaje: algunas condiciones lo suben directamente, como un foco de calor detectado hoy, un índice meteorológico de incendios (FWI) muy alto, o varios focos de calor o focos muy intensos.',
            'driversTitle' => 'Qué significa la causa',
            'drivers' => self::drivers(),
            'driversNote' => 'La columna «Causa» solo se muestra en las comunas de nivel Crítico y Alto. En los demás niveles aparece «—».',
            'sources' => [
                sprintf('Focos de calor: NASA FIRMS, instrumento VIIRS del satélite NOAA-20. Se usan las detecciones de las últimas %d horas, sin las de baja confianza ni las que no tienen coordenadas.', $window),
                'Condiciones meteorológicas: índice meteorológico de incendios (FWI, aproximación simplificada), que mide qué tan favorable es el tiempo actual para que un fuego se inicie y se propague.',
                'Reportes ciudadanos: reportes enviados en la plataforma NoFires durante los últimos 7 días. Se cuentan por región, no por comuna, así que todas las comunas de una región reciben el mismo valor.',
                'Superficie quemada: MapBiomas Chile. Es un valor por año calendario, nunca acumulado.',
                'Nivel de alerta y puntaje de riesgo: NoFires.',
            ],
            'cadence' => [
                'NoFires recalcula el riesgo dos veces al día.',
                sprintf('Este sitio consulta a NoFires cada %s. Los datos de riesgo se guardan %s y los de superficie quemada %s.', self::duration(Cron::INTERVAL), $risk, $burned),
                'Las fechas y horas que ves en las tablas están en hora de Chile.',
            ],
            'limitations' => [
                'Es un índice de riesgo, no probabilidad de incendio: un nivel alto indica condiciones favorables, no que vaya a ocurrir un incendio.',
                'El FWI que usa NoFires es una aproximación simplificada, no el cálculo oficial del sistema canadiense.',
                sprintf('Al %s, NoFires no cuenta con los índices de vegetación y humedad que se obtienen desde satélite (Copernicus), así que calcula solo con el tiempo, los focos de calor y los reportes ciudadanos.', $checked),
                'NoFires informa la hora de cálculo sin zona horaria. Asumimos que es UTC (es una inferencia, no un dato confirmado) y la mostramos en hora de Chile.',
                sprintf('Los focos corresponden a una ventana móvil de %d horas hasta el momento del cálculo. Son detecciones de calor desde satélite, no una cuenta de incendios: un mismo incendio puede dejar varios focos.', $window),
                'La superficie quemada es área afectada por fuego en el año indicado, en cualquier tipo de cobertura; no es deforestación ni pérdida de bosque. En algunas comunas puede faltar y entonces se muestra «Sin dato».',
                sprintf(
                    'Si los datos tienen más de %s, la página avisa que están desactualizados. Si tienen más de %s, los focos se muestran como «%d h previas al» día del último dato.',
                    self::duration(RegionSummarizer::OK_MAX_AGE),
                    self::duration(RegionSummarizer::STALE_MAX_AGE),
                    $window,
                ),
            ],
            'attribution' => [
                'nofiresUrl' => Attribution::NOFIRES_URL,
                'nofires' => 'Riesgo y nivel de alerta:',
                'firms' => 'Focos de calor: NASA FIRMS.',
                'mapbiomas' => Attribution::MAPBIOMAS_CITATION,
            ],
        ];
    }

    /** "15 minutos", "24 horas": seconds spelled out for the reader. */
    public static function duration(int $seconds): string
    {
        [$n, $unit] = $seconds % 3600 === 0 ? [intdiv($seconds, 3600), 'hora'] : [intdiv($seconds, 60), 'minuto'];

        return $n . ' ' . $unit . ($n === 1 ? '' : 's');
    }

    /** es-CL decimal text ("0,85"). */
    private static function decimal(float $n): string
    {
        return number_format($n, 2, ',', '.');
    }

    /** @return list<array{label: string, meaning: string}> */
    private static function drivers(): array
    {
        $meanings = [
            'FIRMS_TODAY' => 'Un satélite detectó al menos un foco de calor en la comuna durante el día de hoy. En la tabla se agrega «hoy» solo cuando el dato es del día.',
            'FIRMS_CLUSTER' => sprintf('Hubo %d o más focos de calor en las últimas %d horas.', self::T['crit_firms'], self::FIRMS_WINDOW_HOURS),
            'FIRMS_FRP' => sprintf('Los focos tienen una intensidad media (potencia radiativa del fuego, FRP) de %d megavatios (MW) o más.', self::T['crit_frp']),
            'FWI' => 'El índice meteorológico de incendios (FWI) es alto: el tiempo del momento favorece que un fuego se inicie y se propague.',
            'SCORE' => 'El nivel se debe sobre todo al puntaje de riesgo, que combina qué tan propenso es el territorio a incendiarse con las condiciones actuales.',
        ];

        $drivers = [];
        foreach ($meanings as $key => $meaning) {
            $drivers[] = ['label' => (string) DriverCopy::label($key, AlertLevel::Critico, false), 'meaning' => $meaning];
        }

        return $drivers;
    }
}
