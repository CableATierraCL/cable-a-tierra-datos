<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Summary;

use CableATierra\Datos\Domain\AlertLevel;
use CableATierra\Datos\Summary\DriverCopy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DriverCopyLabelTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function labels(): array
    {
        return [
            'cluster' => ['FIRMS_CLUSTER', 'Varios focos'],
            'frp' => ['FIRMS_FRP', 'Focos intensos'],
            'fwi' => ['FWI', 'Condiciones meteorológicas'],
            'score' => ['SCORE', 'Puntaje alto'],
        ];
    }

    #[DataProvider('labels')]
    public function test_each_driver_has_a_short_label_for_critico_and_alto(string $driver, string $label): void
    {
        $this->assertSame($label, DriverCopy::label($driver, AlertLevel::Critico, true));
        $this->assertSame($label, DriverCopy::label($driver, AlertLevel::Alto, false));
    }

    public function test_the_firms_today_label_keeps_hoy_only_when_the_row_is_from_today(): void
    {
        $this->assertSame('Foco de calor hoy', DriverCopy::label('FIRMS_TODAY', AlertLevel::Critico, true));
        $this->assertSame('Foco de calor', DriverCopy::label('FIRMS_TODAY', AlertLevel::Critico, false));
    }

    #[DataProvider('otherLevels')]
    public function test_other_levels_never_show_a_cause(AlertLevel $level): void
    {
        $this->assertNull(DriverCopy::label('FWI', $level, true));
    }

    /** @return array<string, array{AlertLevel}> */
    public static function otherLevels(): array
    {
        return ['preventivo' => [AlertLevel::Preventivo], 'normal' => [AlertLevel::Normal], 'unknown' => [AlertLevel::Unknown]];
    }

    #[DataProvider('badDrivers')]
    public function test_a_missing_or_unknown_driver_has_no_label(?string $driver): void
    {
        $this->assertNull(DriverCopy::label($driver, AlertLevel::Critico, true));
    }

    /** @return array<string, array{?string}> */
    public static function badDrivers(): array
    {
        return ['null' => [null], 'empty' => [''], 'unknown' => ['WEIRD'], 'lowercase' => ['fwi']];
    }
}
