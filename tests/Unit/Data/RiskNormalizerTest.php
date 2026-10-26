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

    /** @return array<string, array{mixed}> */
    public static function invalidFirmsCounts(): array
    {
        return [
            'INF' => ['@raw:1e999'],
            'negative INF' => ['@raw:-1e999'],
            'negative' => [-1],
            'fractional' => [2.9],
            'huge' => [1e30],
            'garbage string' => ['abc'],
            'array' => [[1]],
            'bool' => [true],
            'null' => [null],
        ];
    }

    #[DataProvider('invalidFirmsCounts')]
    public function test_invalid_firms_count_becomes_null_never_zero(mixed $value): void
    {
        $rows = (new RiskNormalizer())->normalize($this->body(['firmsCount' => $value]));

        $this->assertNotNull($rows);
        $this->assertNull($rows[0]['firmsCount']);
    }

    /** @return array<string, array{mixed, int}> */
    public static function validFirmsCounts(): array
    {
        return ['int' => [3, 3], 'zero' => [0, 0], 'integral float' => [3.0, 3], 'numeric string' => [' 4 ', 4]];
    }

    #[DataProvider('validFirmsCounts')]
    public function test_valid_firms_count_is_kept(mixed $value, int $expected): void
    {
        $rows = (new RiskNormalizer())->normalize($this->body(['firmsCount' => $value]));

        $this->assertSame($expected, $rows[0]['firmsCount']);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidScores(): array
    {
        return ['INF' => ['@raw:1e999'], 'negative' => [-0.1], 'above one' => [1.5], 'garbage' => ['x'], 'bool' => [false]];
    }

    #[DataProvider('invalidScores')]
    public function test_invalid_score_becomes_null_and_output_stays_json_encodable(mixed $value): void
    {
        $rows = (new RiskNormalizer())->normalize($this->body(['scoreComposite' => $value]));

        $this->assertNull($rows[0]['scoreComposite']);
        $this->assertIsString(json_encode($rows));
    }

    public function test_score_boundaries_are_inclusive(): void
    {
        $zero = (new RiskNormalizer())->normalize($this->body(['scoreComposite' => 0]));
        $one = (new RiskNormalizer())->normalize($this->body(['scoreComposite' => '1']));

        $this->assertSame(0.0, $zero[0]['scoreComposite']);
        $this->assertSame(1.0, $one[0]['scoreComposite']);
    }

    public function test_alert_driver_is_whitelisted(): void
    {
        foreach (['FIRMS_TODAY', 'FWI', 'SCORE', 'FIRMS_CLUSTER', 'FIRMS_FRP'] as $driver) {
            $this->assertSame($driver, (new RiskNormalizer())->normalize($this->body(['alertDriver' => $driver]))[0]['alertDriver']);
        }
        foreach (['<script>alert(1)</script>', 'fwi', '', 5, ['FWI'], null] as $bad) {
            $this->assertNull((new RiskNormalizer())->normalize($this->body(['alertDriver' => $bad]))[0]['alertDriver']);
        }
    }

    public function test_comuna_name_is_cleaned_and_capped(): void
    {
        $clean = (new RiskNormalizer())->normalize($this->body(['nombreComuna' => " Ñuble\x00\x07\n Norte \t"]));
        $long = (new RiskNormalizer())->normalize($this->body(['nombreComuna' => str_repeat('ñ', 200)]));

        $this->assertSame('Ñuble Norte', $clean[0]['nombreComuna']);
        $this->assertSame(120, mb_strlen($long[0]['nombreComuna']));
    }

    public function test_row_is_dropped_when_name_is_empty_after_cleaning(): void
    {
        $this->assertNull((new RiskNormalizer())->normalize($this->body(['nombreComuna' => " \x00\x07\n "])));
    }

    /** @return array<string, array{string}> */
    public static function impossibleDates(): array
    {
        return [
            'feb 31' => ['2026-02-31T10:00:00'],
            'hour 24' => ['2026-10-25T24:00:00'],
            'second 60' => ['2026-10-25T10:00:60'],
            'zero date' => ['0000-00-00T00:00:00'],
            'year too old' => ['1999-12-31T23:59:59'],
            'year too new' => ['2101-01-01T00:00:00'],
            'trailing newline' => ["2026-10-25T10:00:00\n"],
            'offset hour too big' => ['2026-10-25T10:00:00+25:00'],
            'offset minute too big' => ['2026-10-25T10:00:00+02:75'],
            'garbage' => ['not-a-date'],
        ];
    }

    #[DataProvider('impossibleDates')]
    public function test_impossible_computed_at_drops_the_row(string $value): void
    {
        $this->assertNull((new RiskNormalizer())->normalize($this->body(['computedAt' => $value])));
    }

    /** @return array<string, array{string, string}> */
    public static function validDates(): array
    {
        return [
            'naive is utc' => ['2026-10-25T10:00:00', '2026-10-25T10:00:00Z'],
            'fraction' => ['2026-10-25T10:00:00.708', '2026-10-25T10:00:00Z'],
            'zulu' => ['2026-10-25T10:00:00Z', '2026-10-25T10:00:00Z'],
            'offset colon' => ['2026-10-25T10:00:00+02:00', '2026-10-25T08:00:00Z'],
            'offset compact' => ['2026-10-25T10:00:00-0300', '2026-10-25T13:00:00Z'],
            'fraction and offset' => ['2026-10-25T23:30:00.5+01:00', '2026-10-25T22:30:00Z'],
            'leap day' => ['2028-02-29T00:00:00', '2028-02-29T00:00:00Z'],
        ];
    }

    #[DataProvider('validDates')]
    public function test_valid_computed_at_is_converted_to_utc(string $value, string $expected): void
    {
        $rows = (new RiskNormalizer())->normalize($this->body(['computedAt' => $value]));

        $this->assertSame($expected, $rows[0]['computedAt']);
    }

    /** @return array<string, array{string}> */
    public static function nonTrueSuccess(): array
    {
        return [
            'missing' => ['{"data":{"G":{"nombreComuna":"A","alertLevel":"ALTO","computedAt":"2026-10-25T10:00:00"}}}'],
            'string true' => ['{"success":"true","data":{"G":{"nombreComuna":"A","alertLevel":"ALTO","computedAt":"2026-10-25T10:00:00"}}}'],
            'one' => ['{"success":1,"data":{"G":{"nombreComuna":"A","alertLevel":"ALTO","computedAt":"2026-10-25T10:00:00"}}}'],
        ];
    }

    #[DataProvider('nonTrueSuccess')]
    public function test_success_must_be_boolean_true(string $body): void
    {
        $this->assertNull((new RiskNormalizer())->normalize($body));
    }

    public function test_only_string_non_numeric_gids_are_accepted(): void
    {
        $row = '{"nombreComuna":"A","alertLevel":"ALTO","computedAt":"2026-10-25T10:00:00"}';
        $body = '{"success":true,"data":{"123":' . $row . ',"1.5":' . $row . ',"CHL.6.1.1_1":' . $row . '}}';

        $rows = (new RiskNormalizer())->normalize($body);

        $this->assertSame(['CHL.6.1.1_1'], array_column($rows, 'comunaId'));
        $this->assertIsString($rows[0]['comunaId']);
    }

    public function test_unknown_alert_level_is_kept_as_unknown(): void
    {
        $rows = (new RiskNormalizer())->normalize(self::fixture('edge/risk-mixed.json'));

        $this->assertSame('UNKNOWN', $rows[2]['alertLevel']);
        $this->assertSame('CRITICO', $rows[1]['alertLevel']);
    }

    public function test_injected_extra_fields_never_reach_the_output(): void
    {
        $rows = (new RiskNormalizer())->normalize($this->body([
            'comunaId' => 'SPOOFED',
            'components' => ['fwi' => 0.1],
            'newThing' => '<b>x</b>',
            'fwiInputs' => ['tempMax' => 20],
        ]));

        $this->assertCount(1, $rows);
        $this->assertSame(
            ['alertDriver', 'alertLevel', 'computedAt', 'comunaId', 'firmsCount', 'nombreComuna', 'scoreComposite'],
            $this->sortedKeys($rows[0])
        );
        $this->assertSame('CHL.1.1.1_1', $rows[0]['comunaId']);
    }

    /**
     * One valid row under a GADM-style key; "@raw:<json>" values are emitted unquoted
     * so tests can send tokens such as 1e999 that PHP decodes to INF.
     *
     * @param array<string, mixed> $override
     */
    private function body(array $override): string
    {
        $row = array_merge([
            'nombreComuna' => 'Comuna',
            'alertLevel' => 'ALTO',
            'alertDriver' => 'FWI',
            'scoreComposite' => 0.5,
            'firmsCount' => 1,
            'computedAt' => '2026-10-25T10:00:00',
        ], $override);
        $json = json_encode(['success' => true, 'data' => ['CHL.1.1.1_1' => $row]], JSON_THROW_ON_ERROR);

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
