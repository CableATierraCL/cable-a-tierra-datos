<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Wp;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CableATierra\Datos\Wp\Assets;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class DetailAssetsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('plugins_url')->alias(static fn (string $path): string => 'https://site.test/wp-content/plugins/cable-a-tierra-datos/' . $path);
        Functions\when('wp_style_is')->justReturn(false);
        Functions\when('has_shortcode')->alias(static fn (string $content, string $tag): bool => str_contains($content, '[' . $tag));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_it_registers_its_own_stylesheet_versioned_by_modification_time(): void
    {
        $expected = (string) filemtime(CAT_DATOS_DIR . 'assets/css/detail.css');
        Functions\expect('wp_register_style')->once()->with('cat-datos-detail', 'https://site.test/wp-content/plugins/cable-a-tierra-datos/assets/css/detail.css', [], $expected);

        Assets::detail()->register();

        $this->assertNotSame('', $expected);
    }

    public function test_enqueue_enqueues_the_detail_handle_only(): void
    {
        Functions\expect('wp_register_style')->once();
        Functions\expect('wp_enqueue_style')->once()->with('cat-datos-detail');

        Assets::detail()->enqueue();
    }

    public function test_a_singular_page_whose_content_has_the_shortcode_enqueues_up_front(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_queried_object_id')->justReturn(1734);
        Functions\when('get_post_field')->justReturn('Intro [cat_datos_detalle] outro');
        Functions\expect('wp_register_style')->once();
        Functions\expect('wp_enqueue_style')->once()->with('cat-datos-detail');

        Assets::detail()->onEnqueueScripts();
    }

    public function test_a_page_without_the_shortcode_only_registers_the_stylesheet(): void
    {
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_queried_object_id')->justReturn(1);
        Functions\when('get_post_field')->justReturn('[cat_datos_home_band] and [cat_datos_metodologia]');
        Functions\expect('wp_register_style')->once();
        Functions\expect('wp_enqueue_style')->never();

        Assets::detail()->onEnqueueScripts();
    }

    public function test_a_non_singular_request_never_enqueues_it_not_even_the_front_page(): void
    {
        Functions\when('is_singular')->justReturn(false);
        Functions\when('is_front_page')->justReturn(true);
        Functions\expect('wp_register_style')->once();
        Functions\expect('wp_enqueue_style')->never();

        Assets::detail()->onEnqueueScripts();
    }

    public function test_the_hook_never_throws_into_wordpress(): void
    {
        Functions\when('is_singular')->alias(static function (): bool {
            throw new \RuntimeException('boom');
        });
        Functions\expect('wp_register_style')->zeroOrMoreTimes();

        Assets::detail()->onEnqueueScripts();

        $this->addToAssertionCount(1);
    }
}
