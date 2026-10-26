<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Format;

use CableATierra\Datos\Format\LocalTime;
use CableATierra\Datos\Format\SpanishDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalTimeTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function conversions(): array
    {
        return [
            'summer UTC-3 (S-RS-08)' => ['2026-10-25T01:30:00Z', '2026-10-24 22:30'],
            'winter UTC-4 (S-RS-08)' => ['2026-07-01T12:00:00Z', '2026-07-01 08:00'],
            // DST ends first Saturday of April at 24:00 local: 2026-04-05 03:00Z.
            'April last second of DST' => ['2026-04-05T02:59:59Z', '2026-04-04 23:59'],
            'April first second after DST' => ['2026-04-05T03:00:00Z', '2026-04-04 23:00'],
            // DST starts first Saturday of September at 24:00 local: 2026-09-06 04:00Z.
            'September last second before DST' => ['2026-09-06T03:59:59Z', '2026-09-05 23:59'],
            'September first second of DST' => ['2026-09-06T04:00:00Z', '2026-09-06 01:00'],
        ];
    }

    #[DataProvider('conversions')]
    public function testConvertsUtcToSantiagoWithTheIanaZone(string $utc, string $local): void
    {
        $ts = LocalTime::parseUtc($utc);

        $this->assertNotNull($ts);
        $this->assertSame($local, LocalTime::at($ts)->format('Y-m-d H:i'));
    }

    public function testParsesTheNormalizedTimestampAsUtc(): void
    {
        $this->assertSame(gmmktime(10, 30, 0, 10, 25, 2026), LocalTime::parseUtc('2026-10-25T10:30:00Z'));
    }

    public function testRejectsGarbage(): void
    {
        $this->assertNull(LocalTime::parseUtc('not a date'));
        $this->assertNull(LocalTime::parseUtc(''));
        $this->assertNull(LocalTime::parseUtc('2026-02-31T10:00:00Z'));
        $this->assertNull(LocalTime::parseUtc('2026-10-25T10:00:00'));
    }

    public function testDateKeyUsesTheSantiagoDay(): void
    {
        // 02:30Z on the 25th is still the 24th in Chile.
        $this->assertSame('2026-10-24', LocalTime::dateKey(LocalTime::parseUtc('2026-10-25T02:30:00Z') ?? 0));
    }

    public function testSpanishDateFormats(): void
    {
        $d = LocalTime::at(LocalTime::parseUtc('2026-10-24T12:10:00Z') ?? 0);

        $this->assertSame('24 oct, 09:10', SpanishDate::short($d));
        $this->assertSame('24 oct', SpanishDate::day($d));
        $this->assertSame('24 de octubre de 2026, 09:10', SpanishDate::long($d));
    }

    public function testSpanishDateCoversEveryMonthAndUses24Hours(): void
    {
        $short = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        $long = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        foreach (range(1, 12) as $m) {
            $d = LocalTime::at(gmmktime(18, 5, 0, $m, 15, 2026));
            $this->assertStringStartsWith('15 ' . $short[$m - 1] . ',', SpanishDate::short($d));
            $this->assertStringStartsWith('15 de ' . $long[$m - 1] . ' de 2026,', SpanishDate::long($d));
        }
        // 18:05Z in July is 14:05 in Chile (UTC-4); a 12-hour clock would print 02:05.
        $this->assertStringEndsWith('14:05', SpanishDate::short(LocalTime::at(gmmktime(18, 5, 0, 7, 15, 2026))));
    }
}
