<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit;

use CableATierra\Datos\Domain\Region;
use PHPUnit\Framework\TestCase;

final class AutoloaderTest extends TestCase
{
    public function test_plugin_autoloader_resolves_domain_classes(): void
    {
        $this->assertTrue(enum_exists(Region::class));
    }

    public function test_region_enum_exposes_the_three_macrozona_regions(): void
    {
        $values = array_map(static fn (Region $r): string => $r->value, Region::cases());
        sort($values);

        $this->assertSame(['araucania', 'biobio', 'nuble'], $values);
    }

    public function test_plugin_constants_are_defined(): void
    {
        $this->assertTrue(defined('CAT_DATOS_VERSION'));
        $this->assertTrue(defined('CAT_DATOS_DIR'));
    }
}
