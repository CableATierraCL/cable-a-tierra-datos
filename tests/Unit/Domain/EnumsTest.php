<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Domain;

use CableATierra\Datos\Domain\AlertLevel;
use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Domain\Freshness;
use CableATierra\Datos\Domain\Region;
use CableATierra\Datos\Domain\Source;
use PHPUnit\Framework\TestCase;

final class EnumsTest extends TestCase
{
    public function test_region_display_order_and_names(): void
    {
        $this->assertSame(['nuble', 'biobio', 'araucania'], array_map(static fn (Region $r): string => $r->value, Region::cases()));
        $this->assertSame(['Ñuble', 'Biobío', 'La Araucanía'], array_map(static fn (Region $r): string => $r->label(), Region::cases()));
    }

    public function test_region_rejects_unknown_ids(): void
    {
        $this->assertNull(Region::tryFrom('atacama'));
        $this->assertSame(Region::Biobio, Region::tryFrom('biobio'));
    }

    public function test_alert_level_maps_api_strings_and_falls_back_to_unknown(): void
    {
        $this->assertSame(AlertLevel::Critico, AlertLevel::fromApi('CRITICO'));
        $this->assertSame(AlertLevel::Normal, AlertLevel::fromApi('NORMAL'));
        $this->assertSame(AlertLevel::Unknown, AlertLevel::fromApi('EXTREMO'));
        $this->assertSame(AlertLevel::Unknown, AlertLevel::fromApi(''));
    }

    public function test_alert_level_severity_orders_critico_first_and_unknown_last(): void
    {
        $levels = [AlertLevel::Unknown, AlertLevel::Normal, AlertLevel::Critico, AlertLevel::Preventivo, AlertLevel::Alto];
        usort($levels, static fn (AlertLevel $a, AlertLevel $b): int => $a->severity() <=> $b->severity());

        $this->assertSame(
            [AlertLevel::Critico, AlertLevel::Alto, AlertLevel::Preventivo, AlertLevel::Normal, AlertLevel::Unknown],
            $levels
        );
    }

    public function test_known_levels_exclude_unknown_in_fixed_order(): void
    {
        $this->assertSame(
            ['CRITICO', 'ALTO', 'PREVENTIVO', 'NORMAL'],
            array_map(static fn (AlertLevel $l): string => $l->value, AlertLevel::known())
        );
    }

    public function test_dataset_ttls(): void
    {
        $this->assertSame(900, Dataset::Risk->ttl());
        $this->assertSame(86400, Dataset::Burned->ttl());
        $this->assertSame('risk', Dataset::Risk->value);
        $this->assertSame('burned', Dataset::Burned->value);
    }

    public function test_source_and_freshness_values(): void
    {
        $this->assertSame(['cache', 'api', 'last_good', 'none'], array_map(static fn (Source $s): string => $s->value, Source::cases()));
        $this->assertSame(['ok', 'stale', 'very_stale', 'unavailable'], array_map(static fn (Freshness $f): string => $f->value, Freshness::cases()));
    }
}
