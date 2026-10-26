<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Support;

/** Minimal stand-in for WP_Error. */
final class StubWpError
{
    public function get_error_message(): string
    {
        return 'stub error';
    }
}
