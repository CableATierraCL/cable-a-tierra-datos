<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Render;

use CableATierra\Datos\Render\Templates;
use PHPUnit\Framework\TestCase;

final class TemplatesTest extends TestCase
{
    private function templates(): Templates
    {
        return new Templates(dirname(__DIR__, 2) . '/Fixtures/templates');
    }

    public function test_renders_with_view_data_and_trims(): void
    {
        $this->assertSame('<p>Ana</p>', $this->templates()->render('hello', ['who' => 'Ana']));
    }

    public function test_templates_can_render_partials(): void
    {
        $this->assertSame('[n=2]', $this->templates()->render('outer', []));
    }

    public function test_output_buffers_are_cleaned_when_a_template_throws(): void
    {
        $level = ob_get_level();

        try {
            $this->templates()->render('boom', []);
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('x', $e->getMessage());
        }

        $this->assertSame($level, ob_get_level());
    }

    public function test_missing_template_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->templates()->render('nope', []);
    }

    public function test_templates_run_without_this(): void
    {
        $this->assertSame('nothis', $this->templates()->render('leak', []));
    }
}
