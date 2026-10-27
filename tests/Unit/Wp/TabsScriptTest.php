<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Wp;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CableATierra\Datos\Wp\TabsScript;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class TabsScriptTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('plugins_url')->alias(static fn (string $path): string => 'https://site.test/wp-content/plugins/cable-a-tierra-datos/' . $path);
        Functions\when('wp_script_is')->justReturn(false);
        Functions\when('has_shortcode')->alias(static fn (string $content, string $tag): bool => str_contains($content, '[' . $tag));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_it_registers_a_deferred_footer_script_versioned_by_modification_time(): void
    {
        $expected = (string) filemtime(CAT_DATOS_DIR . 'assets/js/tabs.js');
        Functions\expect('wp_register_script')->once()->with(
            'cat-datos-tabs',
            'https://site.test/wp-content/plugins/cable-a-tierra-datos/assets/js/tabs.js',
            [],
            $expected,
            ['strategy' => 'defer', 'in_footer' => true],
        );

        (new TabsScript())->register();

        $this->assertNotSame('', $expected);
    }

    public function test_enqueue_registers_when_needed_and_enqueues_the_handle(): void
    {
        Functions\expect('wp_register_script')->once();
        Functions\expect('wp_enqueue_script')->once()->with('cat-datos-tabs');

        (new TabsScript())->enqueue();
    }

    public function test_a_singular_page_whose_content_has_the_detail_shortcode_enqueues_up_front(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_queried_object_id')->justReturn(1734);
        Functions\when('get_post_field')->justReturn('Intro [cat_datos_detalle] outro');
        Functions\expect('wp_register_script')->once();
        Functions\expect('wp_enqueue_script')->once()->with('cat-datos-tabs');

        (new TabsScript())->onEnqueueScripts();
    }

    public function test_a_methodology_only_page_never_gets_the_script(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_queried_object_id')->justReturn(1);
        Functions\when('get_post_field')->justReturn('Intro [cat_datos_metodologia]');
        Functions\expect('wp_register_script')->once();
        Functions\expect('wp_enqueue_script')->never();

        (new TabsScript())->onEnqueueScripts();
    }

    public function test_a_non_singular_request_never_enqueues_it(): void
    {
        Functions\when('is_singular')->justReturn(false);
        Functions\expect('wp_register_script')->once();
        Functions\expect('wp_enqueue_script')->never();

        (new TabsScript())->onEnqueueScripts();
    }

    public function test_the_hook_never_throws_into_wordpress(): void
    {
        Functions\when('is_singular')->alias(static function (): bool {
            throw new \RuntimeException('boom');
        });
        Functions\expect('wp_register_script')->zeroOrMoreTimes();

        (new TabsScript())->onEnqueueScripts();

        $this->addToAssertionCount(1);
    }
}
