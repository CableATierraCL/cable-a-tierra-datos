<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Summary;

use CableATierra\Datos\Data\DatasetResult;
use CableATierra\Datos\Domain\Freshness;
use CableATierra\Datos\Domain\Source;
use CableATierra\Datos\Summary\RegionSummarizer;
use CableATierra\Datos\Tests\Support\SummaryFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegionSummarizerTest extends TestCase
{
    use SummaryFixtures;

    private function summarize(DatasetResult $risk, ?DatasetResult $burned = null, ?int $now = null): array
    {
        return (new RegionSummarizer())
            ->summarize('nuble', $risk, $burned ?? DatasetResult::none(), $now ?? self::now())
            ->toArray();
    }

    public function testSumsFirmsIgnoringNullAndReportsHowManyWereMissing(): void
    {
        $s = $this->summarize(self::risk([
            self::row('a', firms: 2), self::row('b', firms: 0), self::row('c', firms: 5), self::row('d', firms: null),
        ]));

        $this->assertSame(7, $s['firmsTotal']);
        $this->assertSame(1, $s['firmsMissing']);
    }

    public function testFirmsTotalIsNullNotZeroWhenNoRowHasACount(): void
    {
        $s = $this->summarize(self::risk([self::row('a', firms: null), self::row('b', firms: null)]));

        $this->assertNull($s['firmsTotal']);
        $this->assertSame(2, $s['firmsMissing']);
    }

    public function testRealZeroFirmsStaysZero(): void
    {
        $this->assertSame(0, $this->summarize(self::risk([self::row('a', firms: 0)]))['firmsTotal']);
    }

    public function testLevelCountsAreZeroFilledInFixedOrder(): void
    {
        $rows = [];
        foreach (range(1, 31) as $i) {
            $rows[] = self::row('a' . $i, 'ALTO');
        }
        $rows[] = self::row('n1', 'NORMAL');
        $rows[] = self::row('n2', 'NORMAL');

        $s = $this->summarize(self::risk($rows));

        $this->assertSame(['CRITICO' => 0, 'ALTO' => 31, 'PREVENTIVO' => 0, 'NORMAL' => 2], $s['levelCounts']);
        $this->assertSame(['CRITICO', 'ALTO', 'PREVENTIVO', 'NORMAL'], array_keys($s['levelCounts']));
        $this->assertSame(0, $s['unclassifiedCount']);
        $this->assertSame(33, $s['comunaCount']);
    }

    public function testUnknownLevelIsUnclassifiedAndNotInTheDistribution(): void
    {
        $rows = [self::row('x', 'UNKNOWN')];
        foreach (range(1, 32) as $i) {
            $rows[] = self::row('a' . $i, 'ALTO');
        }

        $s = $this->summarize(self::risk($rows));

        $this->assertSame(1, $s['unclassifiedCount']);
        $this->assertSame(32, array_sum($s['levelCounts']));
        $this->assertSame(33, $s['comunaCount']);
    }

    public function testBurnedSumsAvailableEntriesAndExposesCoverage(): void
    {
        $s = $this->summarize(
            self::risk([self::row('a')]),
            self::burned(['a' => self::ha(10.4), 'b' => self::ha(20.4), 'c' => self::ha(0.0), 'd' => self::ha(null)]),
        );

        $this->assertTrue($s['burned']['available']);
        $this->assertEqualsWithDelta(30.8, $s['burned']['totalHa'], 0.0001);
        $this->assertSame(3, $s['burned']['comunasWithData']);
        $this->assertSame(4, $s['burned']['comunasTotal']);
        $this->assertSame(2025, $s['burned']['year']);
        $this->assertSame('MapBiomas cita', $s['burned']['citation']);
    }

    public function testBurnedWithNoAvailableEntryIsUnavailableNeverZero(): void
    {
        $s = $this->summarize(self::risk([self::row('a')]), self::burned(['a' => self::ha(null), 'b' => self::ha(null)]));

        $this->assertFalse($s['burned']['available']);
        $this->assertNull($s['burned']['totalHa']);
        $this->assertSame(0, $s['burned']['comunasWithData']);
        $this->assertSame(2, $s['burned']['comunasTotal']);
    }

    public function testBurnedMissingEntirelyIsUnavailable(): void
    {
        $s = $this->summarize(self::risk([self::row('a')]));

        $this->assertFalse($s['burned']['available']);
        $this->assertNull($s['burned']['totalHa']);
        $this->assertNull($s['burned']['year']);
        $this->assertNull($s['burned']['citation']);
    }

    public function testGenuineZeroHectaresIsAvailable(): void
    {
        $s = $this->summarize(self::risk([self::row('a')]), self::burned(['a' => self::ha(0.0)]));

        $this->assertTrue($s['burned']['available']);
        $this->assertSame(0.0, $s['burned']['totalHa']);
    }

    public function testFreshnessIsTheOldestComputedAt(): void
    {
        $s = $this->summarize(self::risk([
            self::row('a', computedAt: '2026-10-25T10:30:00Z'),
            self::row('b', computedAt: '2026-10-25T10:00:00Z'),
            self::row('c', computedAt: '2026-10-25T11:00:00Z'),
        ]));

        $this->assertSame('2026-10-25T10:00:00Z', $s['freshnessAt']);
        $this->assertSame('2026-10-25 07:00', $s['freshnessAtLocal']);
        $this->assertSame(7200, $s['ageSeconds']);
    }

    /** @return array<string, array{int, string}> */
    public static function thresholds(): array
    {
        return [
            'exactly 24 h' => [86400, 'ok'],
            '24 h 1 s' => [86401, 'stale'],
            'exactly 48 h' => [172800, 'stale'],
            '48 h 1 s' => [172801, 'very_stale'],
            'fresh' => [60, 'ok'],
        ];
    }

    #[DataProvider('thresholds')]
    public function testStateThresholds(int $age, string $state): void
    {
        $computed = gmdate('Y-m-d\TH:i:s\Z', self::now() - $age);

        $s = $this->summarize(self::risk([self::row('a', computedAt: $computed)]));

        $this->assertSame($state, $s['state']);
        $this->assertSame($age, $s['ageSeconds']);
    }

    public function testRowWithAnUnparsableDateIsExcludedFromEveryCount(): void
    {
        $s = $this->summarize(self::risk([
            self::row('good', 'ALTO', 2),
            self::row('bad-crit', 'CRITICO', 5, 'FWI', 'not-a-date', 'Mala'),
            self::row('bad-unk', 'UNKNOWN', 1, null, ''),
            self::row('crit', 'CRITICO', 1, 'FWI', name: 'Buena'),
        ]));

        $this->assertSame(2, $s['comunaCount']);
        $this->assertSame(['CRITICO' => 1, 'ALTO' => 1, 'PREVENTIVO' => 0, 'NORMAL' => 0], $s['levelCounts']);
        $this->assertSame(0, $s['unclassifiedCount']);
        $this->assertSame(3, $s['firmsTotal']);
        $this->assertSame(0, $s['firmsMissing']);
        $this->assertSame(['Buena'], array_column($s['criticalComunas'], 'nombre'));
    }

    public function testAllRowsUnparsableIsUnavailableWithZeroCounts(): void
    {
        $s = $this->summarize(self::risk([self::row('a', 'CRITICO', 3, 'FWI', 'nope'), self::row('b', 'ALTO', 1, null, '')]));

        $this->assertSame('unavailable', $s['state']);
        $this->assertSame(0, $s['comunaCount']);
        $this->assertSame(0, $s['unclassifiedCount']);
        $this->assertSame(0, $s['firmsMissing']);
        $this->assertNull($s['firmsTotal']);
        $this->assertSame([], $s['criticalComunas']);
        $this->assertSame(['CRITICO' => 0, 'ALTO' => 0, 'PREVENTIVO' => 0, 'NORMAL' => 0], $s['levelCounts']);
    }

    public function testOldestComunaGovernsTheState(): void
    {
        $old = gmdate('Y-m-d\TH:i:s\Z', self::now() - 26 * 3600);

        $s = $this->summarize(self::risk([self::row('a'), self::row('b', computedAt: $old), self::row('c')]));

        $this->assertSame(Freshness::Stale->value, $s['state']);
    }

    public function testNegativeAgeIsOkAndClampedToZero(): void
    {
        $future = gmdate('Y-m-d\TH:i:s\Z', self::now() + 600);

        $s = $this->summarize(self::risk([self::row('a', computedAt: $future)]));

        $this->assertSame('ok', $s['state']);
        $this->assertSame(0, $s['ageSeconds']);
    }

    public function testLastGoodIsJudgedByComputedAtNotByFetchTime(): void
    {
        $computed = gmdate('Y-m-d\TH:i:s\Z', self::now() - 50 * 3600);
        $risk = new DatasetResult(Source::LastGood, [self::row('a', computedAt: $computed)], self::now() - 3600);

        $s = $this->summarize($risk);

        $this->assertSame('very_stale', $s['state']);
        $this->assertSame('last_good', $s['sources']['risk']);
    }

    public function testNoDataIsUnavailableWithNullsNotZeros(): void
    {
        $s = $this->summarize(DatasetResult::none());

        $this->assertSame('unavailable', $s['state']);
        $this->assertNull($s['firmsTotal']);
        $this->assertSame(0, $s['comunaCount']);
        $this->assertNull($s['freshnessAt']);
        $this->assertNull($s['ageSeconds']);
        $this->assertSame([], $s['criticalComunas']);
        $this->assertSame('none', $s['sources']['risk']);
    }

    public function testInvalidRegionIsUnavailableWithoutError(): void
    {
        $s = (new RegionSummarizer())->summarize('atacama', self::risk([self::row('a')]), DatasetResult::none(), self::now())->toArray();

        $this->assertSame('unavailable', $s['state']);
        $this->assertSame('atacama', $s['region']);
    }

    public function testCriticalComunasCarryDriverAndDropUnknownDrivers(): void
    {
        $s = $this->summarize(self::risk([
            self::row('1', 'CRITICO', 3, 'FIRMS_TODAY', name: 'Lonquimay'),
            self::row('2', 'CRITICO', 1, null, name: 'Álamos'),
            self::row('3', 'ALTO', 1, 'FWI', name: 'Otra'),
        ]));

        $this->assertSame(
            [
                ['comunaId' => '2', 'nombre' => 'Álamos', 'driverKey' => null, 'computedAt' => self::NOW],
                ['comunaId' => '1', 'nombre' => 'Lonquimay', 'driverKey' => 'FIRMS_TODAY', 'computedAt' => self::NOW],
            ],
            $s['criticalComunas'],
        );
    }

    public function testSummarizerIsPureAndRepeatable(): void
    {
        $risk = self::risk([self::row('a', 'CRITICO', 2, 'FWI')]);

        $this->assertSame($this->summarize($risk), $this->summarize($risk));
    }

    public function testRegionLabelIsExposed(): void
    {
        $this->assertSame('Ñuble', $this->summarize(self::risk([self::row('a')]))['label']);
    }

    public function testComunasAreSortedBySeverityThenAccentAwareName(): void
    {
        $rows = (new RegionSummarizer())->comunas(self::risk([
            self::row('1', 'NORMAL', name: 'Zeta'),
            self::row('2', 'ALTO', name: 'Ñiquén'),
            self::row('3', 'ALTO', name: 'Nacimiento'),
            self::row('4', 'CRITICO', name: 'Yungay'),
            self::row('5', 'ALTO', name: 'Álamos'),
            self::row('6', 'UNKNOWN', name: 'Aaa'),
        ]));

        $this->assertSame(['Yungay', 'Álamos', 'Nacimiento', 'Ñiquén', 'Zeta', 'Aaa'], array_column($rows, 'nombre'));
        $this->assertSame(
            ['comunaId', 'nombre', 'alertLevel', 'alertDriver', 'score', 'firmsCount', 'computedAtLocal'],
            array_keys($rows[0]),
        );
        $this->assertSame('2026-10-25 09:00', $rows[0]['computedAtLocal']);
    }

    public function testComunasOfUnavailableRegionIsEmpty(): void
    {
        $this->assertSame([], (new RegionSummarizer())->comunas(DatasetResult::none()));
    }
}
