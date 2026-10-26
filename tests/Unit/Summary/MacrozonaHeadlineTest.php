<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Summary;

use CableATierra\Datos\Data\DatasetResult;
use CableATierra\Datos\Summary\DriverCopy;
use CableATierra\Datos\Summary\MacrozonaHeadline;
use CableATierra\Datos\Summary\RegionSummarizer;
use CableATierra\Datos\Summary\RegionSummary;
use CableATierra\Datos\Tests\Support\SummaryFixtures;
use PHPUnit\Framework\TestCase;

final class MacrozonaHeadlineTest extends TestCase
{
    use SummaryFixtures;

    /** @param list<array<string, mixed>> $rows */
    private function summary(string $region, array $rows): RegionSummary
    {
        return (new RegionSummarizer())->summarize($region, $rows === [] ? DatasetResult::none() : self::risk($rows), DatasetResult::none(), self::now());
    }

    /** @param list<RegionSummary> $summaries */
    private function headline(array $summaries): ?string
    {
        return MacrozonaHeadline::compose($summaries, self::now());
    }

    /**
     * Adds a quiet ALTO summary for every region not given, so a test can focus on one clause
     * (a region with no summary at all counts as missing and is named in the text).
     *
     * @param list<RegionSummary> $summaries
     * @return list<RegionSummary>
     */
    private function padded(array $summaries): array
    {
        $have = array_map(static fn (RegionSummary $s): string => $s->region, $summaries);
        foreach (['nuble', 'biobio', 'araucania'] as $region) {
            if (!in_array($region, $have, true)) {
                $summaries[] = $this->summary($region, [self::row('pad-' . $region, 'ALTO')]);
            }
        }

        return $summaries;
    }

    public function testDriverPhrases(): void
    {
        $this->assertSame('foco de calor detectado hoy', DriverCopy::phrase('FIRMS_TODAY', false, null));
        $this->assertSame('focos de calor detectados hoy', DriverCopy::phrase('FIRMS_TODAY', true, null));
        $this->assertSame('varios focos de calor en las últimas 48 h', DriverCopy::phrase('FIRMS_CLUSTER', false, null));
        $this->assertSame('focos de calor de alta intensidad', DriverCopy::phrase('FIRMS_FRP', true, null));
        $this->assertSame('condiciones meteorológicas extremas', DriverCopy::phrase('FWI', false, null));
        $this->assertSame('puntaje de riesgo muy alto', DriverCopy::phrase('SCORE', false, null));
    }

    public function testHoyBecomesADateWhenDataIsNotFromToday(): void
    {
        $this->assertSame('foco de calor detectado el 24 oct', DriverCopy::phrase('FIRMS_TODAY', false, '24 oct'));
        $this->assertSame('focos de calor detectados el 24 oct', DriverCopy::phrase('FIRMS_TODAY', true, '24 oct'));
        $this->assertSame('condiciones meteorológicas extremas', DriverCopy::phrase('FWI', false, '24 oct'));
    }

    public function testNullAndUnknownDriversHaveNoPhrase(): void
    {
        $this->assertNull(DriverCopy::phrase(null, false, null));
        $this->assertNull(DriverCopy::phrase('EXTREMO', false, null));
    }

    public function testSingleCriticalComuna(): void
    {
        $text = $this->headline([
            $this->summary('nuble', [self::row('1', 'CRITICO', 1, 'FIRMS_TODAY', name: 'Yungay')]),
            $this->summary('biobio', [self::row('2', 'ALTO')]),
            $this->summary('araucania', [self::row('3', 'ALTO')]),
        ]);

        $this->assertSame('1 comuna en nivel crítico: Yungay (Ñuble), por un foco de calor detectado hoy.', $text);
    }

    public function testSeveralCriticalComunasAreGroupedByRegionAndDriver(): void
    {
        $text = $this->headline($this->padded([
            $this->summary('biobio', [
                self::row('1', 'CRITICO', 1, 'FIRMS_TODAY', name: 'Alto Biobío'),
                self::row('2', 'CRITICO', 1, 'FIRMS_TODAY', name: 'Mulchén'),
            ]),
            $this->summary('araucania', [self::row('3', 'CRITICO', 0, 'FWI', name: 'Lonquimay')]),
        ]));

        $this->assertSame(
            '3 comunas en nivel crítico: Alto Biobío y Mulchén (Biobío), por focos de calor detectados hoy; Lonquimay (La Araucanía), por condiciones meteorológicas extremas.',
            $text,
        );
    }

    public function testCriticalComunaWithoutDriverOmitsTheClause(): void
    {
        $text = $this->headline($this->padded([$this->summary('nuble', [self::row('1', 'CRITICO', 0, null, name: 'Yungay')])]));

        $this->assertSame('1 comuna en nivel crítico: Yungay (Ñuble).', $text);
    }

    public function testNamesAreCappedAtThree(): void
    {
        $rows = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $n) {
            $rows[] = self::row($n, 'CRITICO', 0, 'FWI', name: 'Comuna ' . $n);
        }

        $text = $this->headline($this->padded([$this->summary('biobio', $rows)]));

        $this->assertSame(
            '5 comunas en nivel crítico: Comuna A, Comuna B y Comuna C (Biobío), por condiciones meteorológicas extremas; y 2 más.',
            $text,
        );
    }

    public function testNoCriticalSaysSoWithTotalsComputedFromThePayload(): void
    {
        $nuble = [];
        foreach (range(1, 21) as $i) {
            $nuble[] = self::row('n' . $i, $i <= 20 ? 'ALTO' : 'NORMAL');
        }
        $biobio = [self::row('b1', 'ALTO'), self::row('b2', 'PREVENTIVO'), self::row('b3', 'UNKNOWN')];

        $text = $this->headline([$this->summary('nuble', $nuble), $this->summary('biobio', $biobio)]);

        $this->assertSame('Ninguna comuna en nivel crítico. 21 de 24 están en nivel alto. Sin datos de La Araucanía.', $text);
    }

    public function testNoCriticalSingularAgreement(): void
    {
        $text = $this->headline([$this->summary('nuble', [self::row('1', 'ALTO'), self::row('2', 'NORMAL')])]);

        $this->assertSame('Ninguna comuna en nivel crítico. 1 de 2 está en nivel alto. Sin datos de Biobío y La Araucanía.', $text);
    }

    public function testMissingRegionIsNamedAndOthersStillCount(): void
    {
        $text = $this->headline([
            $this->summary('nuble', []),
            $this->summary('biobio', [self::row('1', 'CRITICO', 0, 'SCORE', name: 'Cañete')]),
            $this->summary('araucania', [self::row('2', 'ALTO')]),
        ]);

        $this->assertSame('1 comuna en nivel crítico: Cañete (Biobío), por puntaje de riesgo muy alto. Sin datos de Ñuble.', $text);
    }

    public function testAllUnavailableHasNoHeadline(): void
    {
        $this->assertNull($this->headline([$this->summary('nuble', []), $this->summary('biobio', []), $this->summary('araucania', [])]));
        $this->assertNull($this->headline([]));
    }

    public function testHoyIsReplacedByTheDateWhenTheComunaDataIsNotFromToday(): void
    {
        // 2026-10-24 12:00Z is the 24th in Chile; "now" is the 25th.
        $text = $this->headline($this->padded([
            $this->summary('nuble', [self::row('1', 'CRITICO', 1, 'FIRMS_TODAY', '2026-10-24T12:00:00Z', 'Yungay')]),
        ]));

        $this->assertSame('1 comuna en nivel crítico: Yungay (Ñuble), por un foco de calor detectado el 24 oct.', $text);
    }
}
