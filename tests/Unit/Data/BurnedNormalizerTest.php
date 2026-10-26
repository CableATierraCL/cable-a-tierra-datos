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

    /** @return array<string, array{string}> */
    public static function nonTrueSuccess(): array
    {
        $layer = '"data":{"layers":{"BURNED":{"year":2025,"values":{"G":{"available":false}}}}}';

        return [
            'missing' => ['{' . $layer . '}'],
            'string true' => ['{"success":"true",' . $layer . '}'],
            'one' => ['{"success":1,' . $layer . '}'],
        ];
    }

    #[DataProvider('nonTrueSuccess')]
    public function test_success_must_be_boolean_true(string $body): void
    {
        $this->assertNull((new BurnedNormalizer())->normalize($body));
    }

    /** @return array<string, array{mixed, ?int}> */
    public static function years(): array
    {
        return [
            'int' => [2025, 2025],
            'integral float' => [2025.0, 2025],
            'digit string' => ['2025', 2025],
            'fractional' => [2025.5, null],
            'garbage string' => ['20x5', null],
            'padded string' => [' 2025', null],
            'bool' => [true, null],
            'too old' => [1984, null],
            'too new' => [2101, null],
            'INF' => ['@raw:1e999', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('years')]
    public function test_year_validation(mixed $year, ?int $expected): void
    {
        $layer = (new BurnedNormalizer())->normalize($this->body(['year' => $year]));

        $this->assertSame($expected, $layer['year'] ?? null);
    }

    public function test_year_outside_declared_min_max_is_a_failure(): void
    {
        $this->assertNull((new BurnedNormalizer())->normalize($this->body(['year' => 2026, 'minYear' => 2013, 'maxYear' => 2025])));
        $this->assertNull((new BurnedNormalizer())->normalize($this->body(['year' => 2012, 'minYear' => 2013, 'maxYear' => 2025])));
        $this->assertNotNull((new BurnedNormalizer())->normalize($this->body(['year' => 2025, 'minYear' => 2013, 'maxYear' => 2025])));
        $this->assertNotNull((new BurnedNormalizer())->normalize($this->body(['year' => 2030, 'minYear' => 'x', 'maxYear' => null])));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidEntries(): array
    {
        $unavailable = ['available' => false, 'burnedFraction' => null, 'burnedHa' => null];

        return [
            'INF area' => [['available' => true, 'burnedFraction' => 0.1, 'burnedHa' => '@raw:1e999']],
            'negative area' => [['available' => true, 'burnedFraction' => 0.1, 'burnedHa' => -1]],
            'garbage area' => [['available' => true, 'burnedFraction' => 0.1, 'burnedHa' => 'oops']],
            'missing area' => [['available' => true, 'burnedFraction' => 0.1]],
            'non-bool available' => [['available' => 'yes', 'burnedFraction' => 0.1, 'burnedHa' => 5]],
            'null available' => [['available' => null, 'burnedFraction' => 0.1, 'burnedHa' => 5]],
            'missing available' => [['burnedFraction' => 0.1, 'burnedHa' => 5]],
        ];
    }

    /** @param array<string, mixed> $entry */
    #[DataProvider('invalidEntries')]
    public function test_untrustworthy_entries_are_kept_unavailable_with_nulls(array $entry): void
    {
        $layer = (new BurnedNormalizer())->normalize($this->body([], ['G' => $entry, 'OK' => ['available' => true, 'burnedFraction' => 0.5, 'burnedHa' => 2]]));

        $this->assertSame(['available' => false, 'burnedFraction' => null, 'burnedHa' => null], $layer['values']['G']);
        $this->assertTrue($layer['values']['OK']['available']);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidFractions(): array
    {
        return ['INF' => ['@raw:1e999'], 'negative' => [-0.1], 'above one' => [1.01], 'garbage' => ['x']];
    }

    #[DataProvider('invalidFractions')]
    public function test_invalid_fraction_becomes_null_without_losing_the_area(mixed $fraction): void
    {
        $layer = (new BurnedNormalizer())->normalize($this->body([], ['G' => ['available' => true, 'burnedFraction' => $fraction, 'burnedHa' => 3]]));

        $this->assertSame(['available' => true, 'burnedFraction' => null, 'burnedHa' => 3.0], $layer['values']['G']);
        $this->assertIsString(json_encode($layer));
    }

    public function test_citation_is_cleaned_capped_and_empty_becomes_null(): void
    {
        $clean = (new BurnedNormalizer())->normalize($this->body(['citation' => " MapBiomas\x00\x07 2025\n "]));
        $long = (new BurnedNormalizer())->normalize($this->body(['citation' => str_repeat('ñ', 1500)]));
        $empty = (new BurnedNormalizer())->normalize($this->body(['citation' => " \x00\n "]));

        $this->assertSame('MapBiomas 2025', $clean['citation']);
        $this->assertSame(1000, mb_strlen($long['citation']));
        $this->assertNull($empty['citation']);
    }

    public function test_only_string_non_numeric_gids_are_accepted(): void
    {
        $entry = ['available' => false];
        $layer = (new BurnedNormalizer())->normalize($this->body([], ['123' => $entry, '1.5' => $entry, 'CHL.6.1.1_1' => $entry]));

        $this->assertSame(['CHL.6.1.1_1'], array_keys($layer['values']));
        $this->assertSame('CHL.6.1.1_1', array_key_first($layer['values']));
    }

    /**
     * @param array<string, mixed> $layer
     * @param array<string, mixed>|null $values
     */
    private function body(array $layer, ?array $values = null): string
    {
        $layer = array_merge(['year' => 2025, 'values' => $values ?? ['G' => ['available' => false]]], $layer);
        $json = json_encode(['success' => true, 'data' => ['layers' => ['BURNED' => $layer]]], JSON_THROW_ON_ERROR);

        return (string) preg_replace('/"@raw:([^"]+)"/', '$1', $json);
    }

    /** @param array<string, mixed> $row @return list<string> */
    private function sortedKeys(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);

        return $keys;
    }
}
