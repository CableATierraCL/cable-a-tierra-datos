<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Wp;

use CableATierra\Datos\Port\Logger;
use CableATierra\Datos\Port\NullLogger;
use CableATierra\Datos\Wp\WpLogger;
use PHPUnit\Framework\TestCase;

final class WpLoggerTest extends TestCase
{
    private string $file = '';
    private string|false $previous = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = (string) tempnam(sys_get_temp_dir(), 'cat-log');
        $this->previous = ini_set('error_log', $this->file);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previous === false ? '' : $this->previous);
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_when_debug_is_on_it_writes_a_prefixed_line_to_the_error_log(): void
    {
        (new WpLogger(true))->warning('risk/nuble: HTTP 502');

        $this->assertStringContainsString('[cable-a-tierra-datos] risk/nuble: HTTP 502', (string) file_get_contents($this->file));
    }

    public function test_when_debug_is_off_it_writes_nothing(): void
    {
        (new WpLogger(false))->warning('risk/nuble: HTTP 502');

        $this->assertSame('', (string) file_get_contents($this->file));
    }

    public function test_it_is_a_logger_port(): void
    {
        $this->assertInstanceOf(Logger::class, new WpLogger(false));
    }

    public function test_the_null_logger_accepts_and_discards_messages(): void
    {
        (new NullLogger())->warning('anything');

        $this->assertSame('', (string) file_get_contents($this->file));
    }
}
