<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Data;

use CableATierra\Datos\Data\BurnedNormalizer;
use CableATierra\Datos\Tests\Support\FixtureLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BurnedNormalizerTest extends TestCase
{
    use FixtureLoader;

    /** @return array<string, array{string, int}> */
    public static function realFixtures(): array
    {
        return [
            'nuble' => ['nuble', 21],
            'biobio' => ['biobio', 33],
            'araucania' => ['araucania', 32],
        ];
    }

    #[DataProvider('realFixtures')]
    public function test_real_fixture_normalizes_year_citation_and_values(string $region, int $count): void
    {
        $layer = (new BurnedNormalizer())->normalize(self::fixture("nofires/burned-$region.json"));

        $this->assertIsArray($layer);
        $this->assertSame(['citation', 'values', 'year'], $this->sortedKeys($layer));
        $this->assertSame(2025, $layer['year']);
        $this->assertStringStartsWith('MapBiomas', $layer['citation']);
        $this->assertCount($count, $layer['values']);
    }

    public function test_entries_keep_only_whitelisted_fields(): void
    {
        $layer = (new BurnedNormalizer())->normalize(self::fixture('nofires/burned-nuble.json'));

        $this->assertSame(['available', 'burnedFraction', 'burnedHa'], $this->sortedKeys($layer['values']['CHL.13.1.6_1']));
        $this->assertTrue($layer['values']['CHL.13.1.6_1']['available']);
        $this->assertEqualsWithDelta(44.4344, $layer['values']['CHL.13.1.6_1']['burnedHa'], 0.001);
    }

    /** @return array<string, array{string}> */
    public static function failingBodies(): array
    {
        return [
            'invalid json html' => [self::fixture('edge/html-502.html')],
            'empty body' => [''],
            'truncated json' => [self::fixture('edge/truncated.json')],
            'success false' => [self::fixture('edge/burned-success-false.json')],
            'values is a list' => [self::fixture('edge/burned-values-list.json')],
            'scalar values' => ['{"success":true,"data":{"layers":{"BURNED":{"year":2025,"values":7}}}}'],
            'missing values' => ['{"success":true,"data":{"layers":{"BURNED":{"year":2025}}}}'],
            'missing layer' => ['{"success":true,"data":{"layers":{}}}'],
            'missing year' => ['{"success":true,"data":{"layers":{"BURNED":{"values":{"G":{"available":false}}}}}}'],
        ];
    }

    #[DataProvider('failingBodies')]
    public function test_unusable_bodies_are_failures(string $body): void
    {
        $this->assertNull((new BurnedNormalizer())->normalize($body));
    }

    public function test_unavailable_entries_keep_nulls_and_are_never_coerced_to_zero(): void
    {
        $layer = (new BurnedNormalizer())->normalize(self::fixture('edge/burned-partial.json'));

        $this->assertSame(['available' => false, 'burnedFraction' => null, 'burnedHa' => null], $layer['values']['GID.3']);
        $this->assertSame(['available' => true, 'burnedFraction' => 0.0, 'burnedHa' => 0.0], $layer['values']['GID.2']);
    }

    public function test_available_entry_without_numeric_area_is_treated_as_unavailable(): void
    {
        $layer = (new BurnedNormalizer())->normalize(self::fixture('edge/burned-partial.json'));

        $this->assertSame(['available' => false, 'burnedFraction' => null, 'burnedHa' => null], $layer['values']['GID.4']);
    }

    public function test_invalid_entries_are_dropped_and_extra_fields_removed(): void
    {
        $layer = (new BurnedNormalizer())->normalize(self::fixture('edge/burned-partial.json'));

        $this->assertSame(['GID.1', 'GID.2', 'GID.3', 'GID.4'], array_keys($layer['values']));
        $this->assertArrayNotHasKey('extra', $layer['values']['GID.1']);
    }

    public function test_missing_citation_is_allowed_and_becomes_null(): void
    {
        $layer = (new BurnedNormalizer())->normalize(self::fixture('edge/burned-partial.json'));

        $this->assertNull($layer['citation']);
        $this->assertSame(2025, $layer['year']);
    }

    /** @param array<string, mixed> $row @return list<string> */
    private function sortedKeys(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);

        return $keys;
    }
}
