<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Domain;

use CableATierra\Datos\Domain\AlertLevel;
use PHPUnit\Framework\TestCase;

final class AlertLevelLabelsTest extends TestCase
{
    public function test_slugs_are_lowercase_css_names(): void
    {
        $this->assertSame(
            ['critico', 'alto', 'preventivo', 'normal', 'unknown'],
            array_map(static fn (AlertLevel $l): string => $l->slug(), [AlertLevel::Critico, AlertLevel::Alto, AlertLevel::Preventivo, AlertLevel::Normal, AlertLevel::Unknown])
        );
    }

    public function test_labels_are_the_spanish_display_names(): void
    {
        $this->assertSame(
            ['Crítico', 'Alto', 'Preventivo', 'Normal', 'Sin clasificar'],
            array_map(static fn (AlertLevel $l): string => $l->label(), [AlertLevel::Critico, AlertLevel::Alto, AlertLevel::Preventivo, AlertLevel::Normal, AlertLevel::Unknown])
        );
    }

    public function test_unrecognised_api_values_are_unclassified(): void
    {
        $this->assertSame('Sin clasificar', AlertLevel::fromApi('EXTREMO')->label());
    }
}
