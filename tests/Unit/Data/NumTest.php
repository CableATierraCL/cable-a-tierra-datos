<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Data;

use CableATierra\Datos\Data\Num;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NumTest extends TestCase
{
    /** @return array<string, array{mixed, ?float}> */
    public static function floats(): array
    {
        return [
            'int' => [3, 3.0],
            'float' => [0.25, 0.25],
            'numeric string' => ['0.5', 0.5],
            'padded string' => [' 7 ', 7.0],
            'exponent string' => ['1e2', 100.0],
            'INF' => [INF, null],
            'negative INF' => [-INF, null],
            'NAN' => [NAN, null],
            'overflow string' => ['1e999', null],
            'garbage' => ['abc', null],
            'empty string' => ['', null],
            'bool' => [true, null],
            'null' => [null, null],
            'array' => [[1], null],
        ];
    }

    #[DataProvider('floats')]
    public function test_finite_float(mixed $value, ?float $expected): void
    {
        $this->assertSame($expected, Num::finiteFloat($value));
    }

    /** @return array<string, array{mixed, ?int}> */
    public static function ints(): array
    {
        return [
            'int' => [5, 5],
            'zero' => [0, 0],
            'integral float' => [5.0, 5],
            'string' => ['6', 6],
            'integral string' => ['6.0', 6],
            'negative' => [-1, null],
            'fractional' => [2.9, null],
            'huge' => [1e30, null],
            'INF' => [INF, null],
            'NAN' => [NAN, null],
            'overflow string' => ['1e999', null],
            'garbage' => ['x', null],
            'bool' => [false, null],
        ];
    }

    #[DataProvider('ints')]
    public function test_non_negative_int(mixed $value, ?int $expected): void
    {
        $this->assertSame($expected, Num::nonNegativeInt($value));
    }
}
