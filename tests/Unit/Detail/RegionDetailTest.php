<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Detail;

use CableATierra\Datos\Data\DatasetResult;
use CableATierra\Datos\Detail\RegionDetail;
use CableATierra\Datos\Domain\AlertLevel;
use CableATierra\Datos\Domain\Freshness;
use CableATierra\Datos\Domain\Source;
use CableATierra\Datos\Tests\Support\SummaryFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegionDetailTest extends TestCase
{
    use SummaryFixtures;

    private const FRESH = '2026-10-25T10:00:00Z';

    /** @param list<array<string, mixed>> $rows */
    private function build(array $rows, ?DatasetResult $burned = null, ?int $now = null): RegionDetail
    {
        return RegionDetail::build('biobio', self::risk($rows), $burned ?? self::burned([]), $now ?? self::now());
    }

    /** @return list<string> */
    private function ids(RegionDetail $d): array
    {
        return array_map(static fn (array $r): string => $r['comunaId'], $d->rows);
    }

    public function test_it_joins_risk_rows_to_burned_values_by_gid(): void
    {
        $d = $this->build(
            [self::row('G.1', 'ALTO', 3, 'FWI', self::FRESH, 'Uno'), self::row('G.2', 'NORMAL', 0, null, self::FRESH, 'Dos')],
            self::burned(['G.1' => self::ha(12.5), 'G.2' => self::ha(0.0)], 2025, 'Cita'),
        );

        $this->assertSame('biobio', $d->region);
        $this->assertSame('Biobío', $d->label);
        $this->assertSame(['G.1', 'G.2'], $this->ids($d));
        $this->assertSame(12.5, $d->rows[0]['burnedHa']);
        $this->assertSame(0.0, $d->rows[1]['burnedHa'], 'a real zero stays a zero');
        $this->assertSame(2025, $d->year);
        $this->assertSame('Cita', $d->citation);
        $this->assertSame(2, $d->withData);
        $this->assertSame(2, $d->total());
    }

    public function test_a_missing_or_unavailable_burned_entry_is_null_and_never_zero(): void
    {
        $d = $this->build(
            [self::row('G.1', 'ALTO', 1, null, self::FRESH, 'Uno'), self::row('G.2', 'ALTO', 1, null, self::FRESH, 'Dos'), self::row('G.3', 'ALTO', 1, null, self::FRESH, 'Tres')],
            self::burned(['G.1' => self::ha(null), 'G.2' => self::ha(5.0)]),
        );

        // Sorted by name: Dos, Tres, Uno.
        $this->assertSame([5.0, null, null], array_column($d->rows, 'burnedHa'));
        $this->assertSame(1, $d->withData);
        $this->assertSame(3, $d->total());
    }

    public function test_a_burned_dataset_with_no_source_leaves_every_row_without_data_and_no_year(): void
    {
        $d = $this->build([self::row('G.1', 'ALTO', 1, null, self::FRESH, 'Uno')], DatasetResult::none());

        $this->assertSame([null], array_column($d->rows, 'burnedHa'));
        $this->assertNull($d->year);
        $this->assertNull($d->citation);
        $this->assertSame(0, $d->withData);
        $this->assertSame(Freshness::Ok, $d->state);
    }

    public function test_burned_keys_without_a_risk_row_are_ignored(): void
    {
        $d = $this->build([self::row('G.1', 'ALTO', 1, null, self::FRESH, 'Uno')], self::burned(['G.1' => self::ha(1.0), 'ORPHAN' => self::ha(9.0)]));

        $this->assertSame(['G.1'], $this->ids($d));
        $this->assertSame(1, $d->withData);
        $this->assertSame(1, $d->total());
    }

    public function test_rows_sort_by_severity_then_accent_aware_name_with_unknown_last(): void
    {
        $d = $this->build([
            self::row('a', 'NORMAL', 0, null, self::FRESH, 'Ñiquén'),
            self::row('b', 'NORMAL', 0, null, self::FRESH, 'Nacimiento'),
            self::row('c', 'EXTREMO', 0, null, self::FRESH, 'Zeta'),
            self::row('d', 'ALTO', 0, null, self::FRESH, 'Los Ángeles'),
            self::row('e', 'CRITICO', 0, null, self::FRESH, 'Alto Biobío'),
            self::row('f', 'ALTO', 0, null, self::FRESH, 'Arauco'),
            self::row('g', 'NORMAL', 0, null, self::FRESH, 'Ránquil'),
            self::row('h', 'NORMAL', 0, null, self::FRESH, 'Negrete'),
        ]);

        $this->assertSame(['Alto Biobío', 'Arauco', 'Los Ángeles', 'Nacimiento', 'Negrete', 'Ñiquén', 'Ránquil', 'Zeta'], array_column($d->rows, 'name'));
        $this->assertSame(AlertLevel::Unknown, $d->rows[7]['level']);
    }

    public function test_rows_with_an_unparseable_computed_at_are_skipped(): void
    {
        $d = $this->build([self::row('G.1', 'ALTO', 1, null, 'not a date', 'Mala'), self::row('G.2', 'ALTO', 1, null, self::FRESH, 'Buena')]);

        $this->assertSame(['G.2'], $this->ids($d));
        $this->assertSame(1, $d->total());
    }

    public function test_the_row_carries_firms_driver_and_whether_it_is_from_today_in_chile(): void
    {
        // 2026-10-26 02:00 UTC is still the 25th in Chile (23:00), the same day as now (09:00 local).
        $d = $this->build([
            self::row('G.1', 'CRITICO', 4, 'FIRMS_TODAY', '2026-10-25T10:00:00Z', 'Hoy'),
            self::row('G.2', 'CRITICO', null, 'FWI', '2026-10-24T10:00:00Z', 'Ayer'),
            self::row('G.3', 'ALTO', 2, null, '2026-10-26T02:00:00Z', 'Noche'),
        ]);

        $by = array_column($d->rows, null, 'comunaId');
        $this->assertTrue($by['G.1']['today']);
        $this->assertSame('FIRMS_TODAY', $by['G.1']['driver']);
        $this->assertSame(4, $by['G.1']['firms']);
        $this->assertFalse($by['G.2']['today']);
        $this->assertNull($by['G.2']['firms'], 'a missing count is null, not 0');
        $this->assertTrue($by['G.3']['today']);
        $this->assertNull($by['G.3']['driver']);
    }

    public function test_the_freshness_timestamp_is_the_oldest_computed_at(): void
    {
        $d = $this->build([self::row('G.1', 'ALTO', 1, null, '2026-10-25T10:00:00Z', 'A'), self::row('G.2', 'ALTO', 1, null, '2026-10-25T01:30:00Z', 'B')]);

        $this->assertSame(gmmktime(1, 30, 0, 10, 25, 2026), $d->freshnessTs);
    }

    /** @return array<string, array{int, Freshness}> */
    public static function ages(): array
    {
        return [
            'exactly 24 h' => [86400, Freshness::Ok],
            '24 h and a second' => [86401, Freshness::Stale],
            'exactly 48 h' => [172800, Freshness::Stale],
            '48 h and a second' => [172801, Freshness::VeryStale],
            'from the future' => [-600, Freshness::Ok],
        ];
    }

    #[DataProvider('ages')]
    public function test_freshness_boundaries(int $age, Freshness $state): void
    {
        $computedAt = gmdate('Y-m-d\TH:i:s\Z', self::now() - $age);

        $d = $this->build([self::row('G.1', 'ALTO', 1, null, $computedAt, 'A')]);

        $this->assertSame($state, $d->state);
    }

    public function test_no_risk_data_is_unavailable_with_no_rows(): void
    {
        foreach ([RegionDetail::build('biobio', DatasetResult::none(), self::burned([]), self::now()), RegionDetail::unavailable('biobio')] as $d) {
            $this->assertSame(Freshness::Unavailable, $d->state);
            $this->assertSame([], $d->rows);
            $this->assertNull($d->freshnessTs);
            $this->assertSame(0, $d->total());
            $this->assertSame('Biobío', $d->label);
        }
    }

    public function test_a_last_good_source_still_builds_rows(): void
    {
        $d = RegionDetail::build('biobio', self::risk([self::row('G.1', 'ALTO', 1, null, self::FRESH, 'A')], Source::LastGood), self::burned([]), self::now());

        $this->assertCount(1, $d->rows);
    }
}
