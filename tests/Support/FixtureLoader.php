<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Support;

trait FixtureLoader
{
    /** Reads a raw fixture body, e.g. "nofires/risk-nuble.json". */
    private static function fixture(string $relativePath): string
    {
        $body = file_get_contents(dirname(__DIR__) . '/Fixtures/' . $relativePath);
        if ($body === false) {
            throw new \RuntimeException('Missing fixture: ' . $relativePath);
        }

        return $body;
    }
}
