<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Data;

use CableATierra\Datos\Data\RiskNormalizer;
use CableATierra\Datos\Tests\Support\FixtureLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RiskNormalizerTest extends TestCase
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
    public function test_real_fixture_normalizes_every_comuna(string $region, int $count): void
    {
        $rows = (new RiskNormalizer())->normalize(self::fixture("nofires/risk-$region.json"));

        $this->assertIsArray($rows);
        $this->assertCount($count, $rows);
        $this->assertMatchesRegularExpression('/^CHL\.\d+\.\d+\.\d+_1$/', $rows[0]['comunaId']);
    }

    public function test_rows_keep_only_whitelisted_fields_with_utc_computed_at(): void
    {
        $rows = (new RiskNormalizer())->normalize(self::fixture('nofires/risk-nuble.json'));

        $this->assertSame(
            ['alertDriver', 'alertLevel', 'computedAt', 'comunaId', 'firmsCount', 'nombreComuna', 'scoreComposite'],
            $this->sortedKeys($rows[0])
        );
        $this->assertSame('CHL.13.3.5_1', $rows[0]['comunaId']);
        $this->assertSame('San Nicolás', $rows[0]['nombreComuna']);
        $this->assertSame('ALTO', $rows[0]['alertLevel']);
        $this->assertSame('FWI', $rows[0]['alertDriver']);
        $this->assertSame(0, $rows[0]['firmsCount']);
        $this->assertSame(0.4544, $rows[0]['scoreComposite']);
        $this->assertSame('2026-10-25T14:25:07Z', $rows[0]['computedAt']);
    }

    /** @return array<string, array{string}> */
    public static function failingBodies(): array
    {
        return [
            'invalid json html' => [self::fixture('edge/html-502.html')],
            'empty body' => [''],
            'truncated json' => [self::fixture('edge/truncated.json')],
            'bare list' => [self::fixture('edge/risk-bare-list.json')],
            'data is a list' => [self::fixture('edge/risk-data-list.json')],
            'every row invalid' => [self::fixture('edge/risk-all-invalid.json')],
            'success false' => ['{"success":false,"data":{}}'],
            'scalar' => ['42'],
        ];
    }

    #[DataProvider('failingBodies')]
    public function test_unusable_bodies_are_failures(string $body): void
    {
        $this->assertNull((new RiskNormalizer())->normalize($body));
    }

    public function test_invalid_rows_are_dropped_individually(): void
    {
        $rows = (new RiskNormalizer())->normalize(self::fixture('edge/risk-mixed.json'));

        $this->assertSame(['GID.1', 'GID.2', 'GID.3'], array_column($rows, 'comunaId'));
    }

    public function test_missing_optionals_are_tolerated_and_nulls_preserved(): void
    {
        $rows = (new RiskNormalizer())->normalize(self::fixture('edge/risk-mixed.json'));

        $this->assertNull($rows[0]['alertDriver']);
        $this->assertSame(2, $rows[0]['firmsCount']);
        $this->assertSame('FIRMS_TODAY', $rows[1]['alertDriver']);
    }

    public function test_non_numeric_or_null_firms_count_becomes_null_not_zero(): void
    {
        $rows = (new RiskNormalizer())->normalize(self::fixture('edge/risk-mixed.json'));

        $this->assertNull($rows[1]['firmsCount']);
        $this->assertNull($rows[2]['firmsCount']);
    }

    public function test_unknown_alert_level_is_kept_as_unknown(): void
    {
        $rows = (new RiskNormalizer())->normalize(self::fixture('edge/risk-mixed.json'));

        $this->assertSame('UNKNOWN', $rows[2]['alertLevel']);
        $this->assertSame('CRITICO', $rows[1]['alertLevel']);
    }

    public function test_unknown_extra_fields_never_reach_the_output(): void
    {
        $rows = (new RiskNormalizer())->normalize(self::fixture('edge/risk-mixed.json'));

        foreach ($rows as $row) {
            $this->assertArrayNotHasKey('newThing', $row);
            $this->assertArrayNotHasKey('fwiInputs', $row);
            $this->assertArrayNotHasKey('components', $row);
        }
    }

    /** @param array<string, mixed> $row @return list<string> */
    private function sortedKeys(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);

        return $keys;
    }
}
