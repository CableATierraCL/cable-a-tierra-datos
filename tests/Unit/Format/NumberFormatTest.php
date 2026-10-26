<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Format;

use CableATierra\Datos\Format\NumberFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NumberFormatTest extends TestCase
{
    /** @return array<string, array{int|float, string}> */
    public static function integers(): array
    {
        return [
            'zero is a real value' => [0, '0'],
            'below a thousand' => [999, '999'],
            'first thousand' => [1000, '1.000'],
            'millions' => [1234567, '1.234.567'],
            'float rounds half up' => [1234.5, '1.235'],
            'float rounds down' => [1234.4, '1.234'],
        ];
    }

    #[DataProvider('integers')]
    public function test_integer_uses_chilean_thousands_separator(int|float $n, string $expected): void
    {
        self::assertSame($expected, NumberFormat::integer($n));
    }

    /** @return array<string, array{float, string}> */
    public static function hectares(): array
    {
        return [
            'thousands' => [12345.0, "12.345\u{00A0}ha"],
            'half up' => [1234.5, "1.235\u{00A0}ha"],
            'rounds up' => [12345.6, "12.346\u{00A0}ha"],
            'millions' => [1234567.4, "1.234.567\u{00A0}ha"],
            'zero is real' => [0.0, "0\u{00A0}ha"],
            'under one hectare' => [0.4, '<1' . "\u{00A0}ha"],
            'almost one hectare' => [0.99, '<1' . "\u{00A0}ha"],
            'exactly one' => [1.0, "1\u{00A0}ha"],
        ];
    }

    #[DataProvider('hectares')]
    public function test_hectares_use_a_non_breaking_space_before_the_unit(float $ha, string $expected): void
    {
        self::assertSame($expected, NumberFormat::hectares($ha));
    }

    public function test_missing_values_are_never_formatted(): void
    {
        self::assertNull(NumberFormat::integer(null));
        self::assertNull(NumberFormat::hectares(null));
    }
}
