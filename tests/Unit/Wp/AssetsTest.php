<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Wp;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CableATierra\Datos\Wp\Assets;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class AssetsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private bool $registered = false;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->registered = false;
        Functions\when('plugins_url')->alias(static fn (string $path): string => 'https://site.test/wp-content/plugins/cable-a-tierra-datos/' . $path);
        Functions\when('wp_style_is')->alias(fn (): bool => $this->registered);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_it_registers_the_stylesheet_versioned_by_file_modification_time(): void
    {
        $file = CAT_DATOS_DIR . 'assets/css/band.css';
        $expected = (string) filemtime($file);
        Functions\expect('wp_register_style')->once()->with('cat-datos-band', 'https://site.test/wp-content/plugins/cable-a-tierra-datos/assets/css/band.css', [], $expected);

        (new Assets())->register();

        $this->assertNotSame('', $expected);
    }

    public function test_a_missing_file_falls_back_to_the_plugin_version(): void
    {
        Functions\expect('wp_register_style')->once()->with('cat-datos-band', \Mockery::type('string'), [], CAT_DATOS_VERSION);

        (new Assets(sys_get_temp_dir() . '/definitely-missing/'))->register();
    }

    public function test_enqueue_registers_when_needed_and_enqueues_the_handle(): void
    {
        Functions\expect('wp_register_style')->once();
        Functions\expect('wp_enqueue_style')->once()->with('cat-datos-band');

        (new Assets())->enqueue();
    }

    public function test_enqueue_does_not_register_twice(): void
    {
        $this->registered = true;
        Functions\expect('wp_register_style')->never();
        Functions\expect('wp_enqueue_style')->once()->with('cat-datos-band');

        (new Assets())->enqueue();
    }

    public function test_on_the_front_page_the_hook_enqueues_the_stylesheet(): void
    {
        Functions\when('is_front_page')->justReturn(true);
        Functions\expect('wp_register_style')->once();
        Functions\expect('wp_enqueue_style')->once()->with('cat-datos-band');

        (new Assets())->onEnqueueScripts();
    }

    public function test_elsewhere_the_hook_only_registers_the_stylesheet(): void
    {
        Functions\when('is_front_page')->justReturn(false);
        Functions\expect('wp_register_style')->once();
        Functions\expect('wp_enqueue_style')->never();

        (new Assets())->onEnqueueScripts();
    }

    public function test_the_hook_never_throws_into_wordpress(): void
    {
        Functions\when('is_front_page')->alias(static function (): bool {
            throw new \RuntimeException('boom');
        });
        Functions\expect('wp_register_style')->zeroOrMoreTimes();

        (new Assets())->onEnqueueScripts();

        $this->addToAssertionCount(1);
    }
}
