<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Wp;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CableATierra\Datos\Data\Repository;
use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Port\Clock;
use CableATierra\Datos\Port\HttpResponse;
use CableATierra\Datos\Render\BandRenderer;
use CableATierra\Datos\Tests\Support\FakeCache;
use CableATierra\Datos\Tests\Support\FakeClock;
use CableATierra\Datos\Tests\Support\FakeHttpClient;
use CableATierra\Datos\Tests\Support\FakeScheduler;
use CableATierra\Datos\Tests\Support\FixtureLoader;
use CableATierra\Datos\Tests\Support\RecordingLogger;
use CableATierra\Datos\Wp\Assets;
use CableATierra\Datos\Wp\Shortcode;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class ShortcodeTest extends TestCase
{
    use FixtureLoader;
    use MockeryPHPUnitIntegration;

    private FakeClock $clock;
    private FakeHttpClient $http;
    private FakeCache $cache;
    private RecordingLogger $logger;
    private Repository $repository;

    /** @var list<string> */
    private array $enqueued = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        BandRenderer::resetInstances();
        Functions\when('esc_html')->alias(static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8', false));
        Functions\when('esc_attr')->alias(static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8', false));
        Functions\when('esc_url')->alias(static fn (string $u): string => htmlspecialchars($u, ENT_QUOTES, 'UTF-8', false));
        Functions\when('esc_url_raw')->alias(static fn (string $u): string => $u);
        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'https://site.test' . $path);
        Functions\when('plugins_url')->justReturn('https://site.test/band.css');
        Functions\when('wp_style_is')->justReturn(true);
        $this->enqueued = [];
        Functions\when('wp_enqueue_style')->alias(function (string $handle): void {
            $this->enqueued[] = $handle;
        });

        $this->clock = new FakeClock();
        $this->http = new FakeHttpClient();
        $this->cache = new FakeCache($this->clock);
        $this->logger = new RecordingLogger();
        $this->repository = new Repository($this->http, $this->clock, $this->cache, new FakeScheduler(), logger: $this->logger);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function shortcode(?Clock $clock = null): Shortcode
    {
        return new Shortcode($this->repository, $clock ?? $this->clock, new BandRenderer(), $this->logger, new Assets());
    }

    private function warmCache(): void
    {
        foreach (['nuble', 'biobio', 'araucania'] as $region) {
            foreach (Dataset::cases() as $dataset) {
                $this->http->queue(new HttpResponse(200, self::fixture("nofires/{$dataset->value}-$region.json")));
                $this->repository->refresh($region, $dataset);
            }
        }
        $this->logger->messages = [];
    }

    public function test_it_registers_the_shortcode_tag(): void
    {
        Functions\expect('add_shortcode')->once()->with('cat_datos_home_band', \Mockery::type('callable'));

        $this->shortcode()->register();
    }

    public function test_with_warm_data_it_renders_one_card_per_region_in_fixed_order(): void
    {
        $this->warmCache();

        $html = $this->shortcode()->render();

        $this->assertSame(3, substr_count($html, '<article class="cat-region"'));
        preg_match_all('~<h3 class="cat-region__name"[^>]*>([^<]+)</h3>~u', $html, $names);
        $this->assertSame(['Ñuble', 'Biobío', 'La Araucanía'], $names[1]);
        $this->assertStringContainsString('id="datos"', $html);
        $this->assertSame([], $this->logger->messages);
    }

    public function test_it_enqueues_the_stylesheet_when_it_renders(): void
    {
        $this->warmCache();

        $this->shortcode()->render();

        $this->assertSame(['cat-datos-band'], $this->enqueued);
    }

    public function test_with_everything_unavailable_it_still_returns_the_band_with_a_message(): void
    {
        // Nothing cached and every request fails: the page must still get usable markup.
        $html = $this->shortcode()->render();

        $this->assertNotSame('', $html);
        $this->assertStringContainsString('El territorio, medido.', $html);
        $this->assertStringContainsString('Datos no disponibles por el momento', $html);
        $this->assertStringNotContainsString('<article', $html);
    }

    public function test_attributes_content_and_tag_are_ignored(): void
    {
        $this->warmCache();
        $plain = $this->shortcode()->render();
        BandRenderer::resetInstances();

        $withAttributes = $this->shortcode()->render(['region' => '<script>alert(1)</script>', 'class' => 'x" onload="y'], 'inner', 'cat_datos_home_band');

        $this->assertSame($plain, $withAttributes);
    }

    public function test_attributes_given_as_the_empty_string_are_accepted(): void
    {
        $this->warmCache();

        $html = $this->shortcode()->render('');

        $this->assertStringContainsString('<article', $html);
    }

    public function test_a_throwing_dependency_degrades_to_the_unavailable_band_and_is_logged(): void
    {
        $clock = new class implements Clock {
            public function now(): int
            {
                throw new \RuntimeException('clock exploded');
            }
        };

        $html = $this->shortcode($clock)->render();

        $this->assertStringContainsString('Datos no disponibles por el momento', $html);
        $this->assertStringContainsString('El territorio, medido.', $html);
        $this->assertSame(['shortcode: unexpected RuntimeException: clock exploded'], $this->logger->messages);
    }

    public function test_a_failing_stylesheet_never_blocks_the_markup(): void
    {
        $this->warmCache();
        Functions\when('wp_enqueue_style')->alias(static function (): void {
            throw new \RuntimeException('no assets today');
        });

        $html = $this->shortcode()->render();

        $this->assertStringContainsString('<article', $html);
        $this->assertSame(['shortcode: stylesheet failed: RuntimeException: no assets today'], $this->logger->messages);
    }

    public function test_two_bands_in_one_request_do_not_duplicate_the_anchor_id(): void
    {
        $this->warmCache();

        $first = $this->shortcode()->render();
        $second = $this->shortcode()->render();

        $this->assertSame(1, substr_count($first . $second, 'id="datos"'));
    }
}
