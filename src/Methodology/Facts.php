<?php

declare(strict_types=1);

namespace CableATierra\Datos\Methodology;

use CableATierra\Datos\Domain\AlertLevel;
use CableATierra\Datos\Domain\Attribution;
use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Summary\DriverCopy;
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

    private const T = self::THRESHOLDS;

    /** @return array<string, mixed> Plain text only; escaping happens in the template. */
    public static function view(): array
    {
        $fwi = 'El índice meteorológico de incendios (FWI) es de %d o más.';
        $score = 'El puntaje de riesgo es de %s o más.';
        $risk = self::duration(Dataset::Risk->ttl());
        $burned = self::duration(Dataset::Burned->ttl());

        return [
            'title' => 'Cómo se calcula',
            'intro' => 'NoFires calcula el nivel de alerta de cada comuna con las reglas de más abajo. Se revisan en orden, de la más grave a la menos grave, y gana la primera que se cumple.',
            'levels' => [
                ['name' => AlertLevel::Critico->label(), 'lead' => 'Se cumple al menos una de estas condiciones:', 'items' => [
                    'Hay un foco de calor detectado hoy en la comuna (según la fecha de Chile).',
                    sprintf($fwi, self::T['crit_fwi']),
                    sprintf($score, self::decimal(self::T['crit_score'])),
                    sprintf('Hubo %d o más focos de calor en las últimas 48 horas.', self::T['crit_firms']),
                    sprintf('La intensidad media de los focos (potencia radiativa del fuego, FRP) es de %d o más.', self::T['crit_frp']),
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
            'scoreNote' => 'El puntaje de riesgo va de 0 a 1. Combina, en partes aproximadamente iguales, la susceptibilidad estructural del territorio (según MapBiomas) y las condiciones actuales.',
            'driversTitle' => 'Qué significa la causa',
            'drivers' => self::drivers(),
            'driversNote' => 'La columna «Causa» solo se muestra en las comunas de nivel Crítico y Alto. En los demás niveles aparece «—».',
            'sources' => [
                'Focos de calor: NASA FIRMS, satélite VIIRS_NOAA20_NRT. Se usan las detecciones de las últimas 48 horas, sin las de baja confianza ni las que no tienen coordenadas.',
                'Clima: índice meteorológico de incendios (FWI), que mide qué tan favorable es el tiempo actual para que un fuego se inicie y se propague.',
                'Superficie quemada: MapBiomas Chile. Es un valor por año calendario, nunca acumulado.',
                'Nivel de alerta y puntaje de riesgo: NoFires.',
            ],
            'cadence' => [
                'NoFires recalcula el riesgo dos veces al día, a las 01:30 y a las 13:30 (hora UTC).',
                sprintf('Este sitio consulta a NoFires cada %s. Los datos de riesgo se guardan %s y los de superficie quemada %s.', self::duration(Cron::INTERVAL), $risk, $burned),
                'Las fechas y horas que ves en las tablas están en hora de Chile.',
            ],
            'limitations' => [
                'Es un índice de riesgo, no probabilidad de incendio: un nivel alto indica condiciones favorables, no que vaya a ocurrir un incendio.',
                'Hoy NoFires no cuenta con los índices satelitales de vegetación y humedad (Copernicus NDVI y NDMI), así que calcula en modo STANDARD: solo con el índice meteorológico, los focos de calor y los reportes.',
                'NoFires informa la hora de cálculo sin zona horaria. Asumimos que es UTC (es una inferencia, no un dato confirmado) y la mostramos en hora de Chile.',
                'Los focos corresponden a una ventana móvil de 48 horas hasta el momento del cálculo. Son detecciones de calor desde satélite, no una cuenta de incendios: un mismo incendio puede dejar varios focos.',
                'La superficie quemada es área afectada por fuego en el año indicado, en cualquier tipo de cobertura; no es deforestación ni pérdida de bosque. En algunas comunas puede faltar y entonces se muestra «Sin dato».',
                'Si los datos tienen más de 24 horas, la página avisa que están desactualizados y muestra la fecha del último dato.',
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
            'FIRMS_CLUSTER' => sprintf('Hubo %d o más focos de calor en las últimas 48 horas.', self::T['crit_firms']),
            'FIRMS_FRP' => sprintf('Los focos tienen una intensidad media (potencia radiativa del fuego, FRP) de %d o más.', self::T['crit_frp']),
            'FWI' => 'El índice meteorológico de incendios (FWI) es alto: el tiempo del momento favorece que un fuego se inicie y se propague.',
            'SCORE' => 'El nivel se debe sobre todo al puntaje de riesgo, que combina la susceptibilidad del territorio con las condiciones actuales.',
        ];

        $drivers = [];
        foreach ($meanings as $key => $meaning) {
            $drivers[] = ['label' => (string) DriverCopy::label($key, AlertLevel::Critico, false), 'meaning' => $meaning];
        }

        return $drivers;
    }
}
