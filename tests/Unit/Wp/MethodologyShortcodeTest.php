<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Wp;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CableATierra\Datos\Render\Templates;
use CableATierra\Datos\Tests\Support\DetailHtml;
use CableATierra\Datos\Tests\Support\RecordingLogger;
use CableATierra\Datos\Wp\Assets;
use CableATierra\Datos\Wp\MethodologyShortcode;
use CableATierra\Datos\Wp\Plugin;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class MethodologyShortcodeTest extends TestCase
{
    use DetailHtml;
    use MockeryPHPUnitIntegration;

    /** @var list<string> */
    private array $enqueued = [];

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->stubEscapers();
        Functions\when('plugins_url')->justReturn('https://site.test/detail.css');
        Functions\when('wp_style_is')->justReturn(true);
        $this->enqueued = [];
        Functions\when('wp_enqueue_style')->alias(function (string $handle): void {
            $this->enqueued[] = $handle;
        });
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function shortcode(?Templates $templates = null): MethodologyShortcode
    {
        return new MethodologyShortcode($this->logger, Assets::detail(), $templates ?? new Templates());
    }

    public function test_it_registers_the_tag(): void
    {
        Functions\expect('add_shortcode')->once()->with('cat_datos_metodologia', \Mockery::type('array'));

        $this->shortcode()->register();
    }

    public function test_the_plugin_registers_it_on_init(): void
    {
        $registered = [];
        Functions\when('add_action')->alias(static function (string $hook, $callback) use (&$registered): void {
            $registered[$hook][] = $callback;
        });

        Plugin::boot();

        $this->assertContains([Plugin::class, 'registerMethodologyShortcode'], $registered['init']);
    }

    public function test_the_plugin_builds_it_without_a_repository(): void
    {
        Functions\expect('add_shortcode')->once()->with('cat_datos_metodologia', \Mockery::type('array'));

        Plugin::registerMethodologyShortcode();
    }

    public function test_it_renders_the_methodology_and_enqueues_the_stylesheet_only(): void
    {
        $html = $this->shortcode()->render();

        $this->assertSame(1, $this->xpath($html)->query('//section[@id="metodologia"]')->length);
        $this->assertSame(['cat-datos-detail'], $this->enqueued);
    }

    public function test_it_never_registers_or_enqueues_a_script(): void
    {
        Functions\expect('wp_enqueue_script')->never();
        Functions\expect('wp_register_script')->never();

        $this->shortcode()->render();
        $this->addToAssertionCount(1);
    }

    public function test_it_has_no_way_to_reach_data_and_touches_no_storage_or_network(): void
    {
        foreach (['get_option', 'update_option', 'get_transient', 'set_transient', 'wp_remote_get', 'wp_remote_request', 'wp_cache_get', 'wp_schedule_single_event'] as $function) {
            Functions\expect($function)->never();
        }
        $types = array_map(
            static fn (\ReflectionParameter $p): string => (string) $p->getType(),
            (new \ReflectionMethod(MethodologyShortcode::class, '__construct'))->getParameters(),
        );
        sort($types);

        $this->assertSame([\CableATierra\Datos\Port\Logger::class, Templates::class, Assets::class], $types);
        $this->assertNotSame('', $this->shortcode()->render(['ignored' => '1']));
    }

    public function test_it_is_unaffected_by_attributes(): void
    {
        $this->assertSame($this->shortcode()->render(), $this->shortcode()->render(['x' => 'y'], 'content', 'cat_datos_metodologia'));
    }

    public function test_a_template_failure_logs_and_still_returns_a_labelled_section(): void
    {
        $html = $this->shortcode(new Templates(CAT_DATOS_DIR . 'tests/Fixtures/templates/nonexistent'))->render();

        $x = $this->xpath($html);
        $this->assertSame(1, $x->query('//section[@id="metodologia"]//h2')->length);
        $this->assertSame(1, count($this->logger->messages));
        $this->assertStringContainsString('methodology shortcode', $this->logger->messages[0]);
    }

    public function test_a_stylesheet_failure_does_not_stop_the_content(): void
    {
        Functions\when('wp_enqueue_style')->alias(static function (): void {
            throw new \RuntimeException('boom');
        });

        $html = $this->shortcode()->render();

        $this->assertSame(1, $this->xpath($html)->query('//section[@id="metodologia"]')->length);
        $this->assertStringContainsString('stylesheet', $this->logger->messages[0]);
    }

    public function test_a_throwing_logger_never_escapes(): void
    {
        $logger = new class implements \CableATierra\Datos\Port\Logger {
            public function warning(string $message): void
            {
                throw new \RuntimeException('log');
            }
        };
        Functions\when('wp_enqueue_style')->alias(static function (): void {
            throw new \RuntimeException('boom');
        });

        $html = (new MethodologyShortcode($logger, Assets::detail(), new Templates(CAT_DATOS_DIR . 'nowhere')))->render();

        $this->assertStringContainsString('id="metodologia"', $html);
    }
}
