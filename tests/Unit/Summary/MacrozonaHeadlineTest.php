<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Summary;

use CableATierra\Datos\Data\DatasetResult;
use CableATierra\Datos\Domain\Freshness;
use CableATierra\Datos\Summary\DriverCopy;
use CableATierra\Datos\Summary\MacrozonaHeadline;
use CableATierra\Datos\Summary\RegionSummarizer;
use CableATierra\Datos\Summary\RegionSummary;
use CableATierra\Datos\Tests\Support\SummaryFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return array<string, array{string, bool, ?string, ?string}> */
    public static function clauses(): array
    {
        return [
            'today, singular, today' => ['FIRMS_TODAY', false, 'hoy', 'por un foco de calor detectado hoy'],
            'today, plural, today' => ['FIRMS_TODAY', true, 'hoy', 'por focos de calor detectados hoy'],
            'today, singular, past' => ['FIRMS_TODAY', false, 'el 24 oct', 'por un foco de calor detectado el 24 oct'],
            'today, plural, past' => ['FIRMS_TODAY', true, 'el 24 oct', 'por focos de calor detectados el 24 oct'],
            'today, singular, unknown date' => ['FIRMS_TODAY', false, null, 'por un foco de calor detectado'],
            'today, plural, unknown date' => ['FIRMS_TODAY', true, null, 'por focos de calor detectados'],
            'cluster, singular, today' => ['FIRMS_CLUSTER', false, 'hoy', 'por varios focos de calor en las últimas 48 h'],
            'cluster, plural, past' => ['FIRMS_CLUSTER', true, 'el 24 oct', 'por varios focos de calor en las últimas 48 h'],
            'frp, singular, today' => ['FIRMS_FRP', false, 'hoy', 'por focos de calor de alta intensidad'],
            'frp, plural, past' => ['FIRMS_FRP', true, 'el 24 oct', 'por focos de calor de alta intensidad'],
            'fwi, singular, today' => ['FWI', false, 'hoy', 'por condiciones meteorológicas extremas'],
            'fwi, plural, past' => ['FWI', true, 'el 24 oct', 'por condiciones meteorológicas extremas'],
            'score, singular, today' => ['SCORE', false, 'hoy', 'por un puntaje de riesgo muy alto'],
            'score, plural, past' => ['SCORE', true, 'el 24 oct', 'por un puntaje de riesgo muy alto'],
            'no driver' => [null, false, 'hoy', null],
            'unknown driver' => ['EXTREMO', false, 'hoy', null],
        ];
    }

    #[DataProvider('clauses')]
    public function testDriverClauses(?string $driver, bool $plural, ?string $cuando, ?string $expected): void
    {
        $this->assertSame($expected, DriverCopy::clause($driver, $plural, $cuando));
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
            '5 comunas en nivel crítico: Comuna A, Comuna B y Comuna C (Biobío), por condiciones meteorológicas extremas, y 2 más.',
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

        $this->assertSame('Ninguna comuna en nivel crítico. 21 de 24 están en nivel alto. 1 comuna sin clasificar. Sin datos de La Araucanía.', $text);
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

        $this->assertSame('1 comuna en nivel crítico: Cañete (Biobío), por un puntaje de riesgo muy alto. Sin datos de Ñuble.', $text);
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

    public function testHoyUsesTheChileDayNotTheUtcDay(): void
    {
        // 02:30Z on the 25th is 23:30 on the 24th in Chile (UTC-3); "now" is 07:00 on the 25th there.
        $now = gmmktime(10, 0, 0, 10, 25, 2026);
        $summary = (new RegionSummarizer())->summarize(
            'nuble',
            self::risk([self::row('1', 'CRITICO', 1, 'FIRMS_TODAY', '2026-10-25T02:30:00Z', 'Yungay')]),
            DatasetResult::none(),
            $now,
        );

        $text = MacrozonaHeadline::compose($this->padded([$summary]), $now);

        $this->assertSame('1 comuna en nivel crítico: Yungay (Ñuble), por un foco de calor detectado el 24 oct.', $text);
    }

    public function testStaleRegionIsNamedAfterTheCriticalSentence(): void
    {
        $old = gmdate('Y-m-d\TH:i:s\Z', self::now() - 30 * 3600);

        $text = $this->headline($this->padded([
            $this->summary('nuble', [self::row('1', 'CRITICO', 1, 'FWI', $old, 'Yungay')]),
        ]));

        $this->assertSame('1 comuna en nivel crítico: Yungay (Ñuble), por condiciones meteorológicas extremas. Datos desactualizados de Ñuble.', $text);
    }

    public function testStaleRegionsAreNamedAfterTheNoCriticalSentence(): void
    {
        $stale = gmdate('Y-m-d\TH:i:s\Z', self::now() - 30 * 3600);
        $veryStale = gmdate('Y-m-d\TH:i:s\Z', self::now() - 60 * 3600);

        $two = $this->headline($this->padded([
            $this->summary('nuble', [self::row('1', 'ALTO', 0, null, $stale)]),
            $this->summary('araucania', [self::row('2', 'ALTO', 0, null, $veryStale)]),
        ]));
        $three = $this->headline([
            $this->summary('nuble', [self::row('1', 'ALTO', 0, null, $stale)]),
            $this->summary('biobio', [self::row('2', 'ALTO', 0, null, $stale)]),
            $this->summary('araucania', [self::row('3', 'ALTO', 0, null, $veryStale)]),
        ]);

        $this->assertSame('Ninguna comuna en nivel crítico. 3 de 3 están en nivel alto. Datos desactualizados de Ñuble y La Araucanía.', $two);
        $this->assertSame('Ninguna comuna en nivel crítico. 3 de 3 están en nivel alto. Datos desactualizados de Ñuble, Biobío y La Araucanía.', $three);
    }

    public function testFreshRegionsAddNoStaleSentence(): void
    {
        $text = $this->headline($this->padded([$this->summary('nuble', [self::row('1', 'CRITICO', 1, 'FWI', name: 'Yungay')])]));

        $this->assertStringNotContainsString('desactualizados', (string) $text);
    }

    public function testUnclassifiedComunasAreCounted(): void
    {
        $one = $this->headline($this->padded([$this->summary('nuble', [self::row('1', 'CRITICO', 1, 'FWI', name: 'Yungay'), self::row('2', 'UNKNOWN')])]));
        $three = $this->headline($this->padded([$this->summary('nuble', [self::row('1', 'ALTO'), self::row('2', 'UNKNOWN'), self::row('3', 'UNKNOWN')]), $this->summary('biobio', [self::row('4', 'UNKNOWN')])]));

        $this->assertSame('1 comuna en nivel crítico: Yungay (Ñuble), por condiciones meteorológicas extremas. 1 comuna sin clasificar.', $one);
        $this->assertSame('Ninguna comuna en nivel crítico. 2 de 5 están en nivel alto. 3 comunas sin clasificar.', $three);
    }

    public function testAllUnclassifiedMakesNoLevelClaim(): void
    {
        $stale = gmdate('Y-m-d\TH:i:s\Z', self::now() - 30 * 3600);

        $text = $this->headline([
            $this->summary('nuble', [self::row('1', 'UNKNOWN', 0, null, $stale), self::row('2', 'UNKNOWN', 0, null, $stale)]),
            $this->summary('biobio', [self::row('3', 'UNKNOWN')]),
        ]);

        $this->assertSame('Datos desactualizados de Ñuble. 3 comunas sin clasificar. Sin datos de La Araucanía.', $text);
    }

    public function testCriticalComunaWithAnUnparsableDateOmitsHoyAndTheDate(): void
    {
        $summary = new RegionSummary(
            'nuble',
            'Ñuble',
            Freshness::Ok,
            1,
            0,
            ['CRITICO' => 1, 'ALTO' => 0, 'PREVENTIVO' => 0, 'NORMAL' => 0],
            0,
            1,
            [['comunaId' => '1', 'nombre' => 'Yungay', 'driverKey' => 'FIRMS_TODAY', 'computedAt' => 'garbage']],
            ['available' => false, 'year' => null, 'totalHa' => null, 'comunasWithData' => 0, 'comunasTotal' => 0, 'citation' => null],
            self::now(),
            0,
            ['risk' => 'cache', 'burned' => 'none'],
        );

        $text = MacrozonaHeadline::compose($this->padded([$summary]), self::now());

        $this->assertSame('1 comuna en nivel crítico: Yungay (Ñuble), por un foco de calor detectado.', $text);
    }

    public function testComposeNeverThrows(): void
    {
        // A non-string driver key violates the types and raises a TypeError under strict_types.
        $broken = new RegionSummary(
            'nuble',
            'Ñuble',
            Freshness::Ok,
            1,
            0,
            ['CRITICO' => 1, 'ALTO' => 0, 'PREVENTIVO' => 0, 'NORMAL' => 0],
            0,
            1,
            [['comunaId' => '1', 'nombre' => 'Yungay', 'driverKey' => 5, 'computedAt' => self::NOW]],
            ['available' => false, 'year' => null, 'totalHa' => null, 'comunasWithData' => 0, 'comunasTotal' => 0, 'citation' => null],
            self::now(),
            0,
            ['risk' => 'cache', 'burned' => 'none'],
        );

        $this->assertNull(MacrozonaHeadline::compose([$broken], self::now()));
    }
}
