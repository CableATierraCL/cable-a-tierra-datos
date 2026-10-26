<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Wp;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use CableATierra\Datos\Data\Repository;
use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Port\Clock;
use CableATierra\Datos\Port\HttpResponse;
use CableATierra\Datos\Render\DetailRenderer;
use CableATierra\Datos\Summary\Api;
use CableATierra\Datos\Tests\Support\DetailHtml;
use CableATierra\Datos\Tests\Support\FakeCache;
use CableATierra\Datos\Tests\Support\FakeClock;
use CableATierra\Datos\Tests\Support\FakeHttpClient;
use CableATierra\Datos\Tests\Support\FakeScheduler;
use CableATierra\Datos\Tests\Support\FixtureLoader;
use CableATierra\Datos\Tests\Support\RecordingLogger;
use CableATierra\Datos\Tests\Support\SummaryFixtures;
use CableATierra\Datos\Wp\Assets;
use CableATierra\Datos\Wp\DetailShortcode;
use CableATierra\Datos\Wp\Plugin;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class DetailShortcodeTest extends TestCase
{
    use DetailHtml;
    use FixtureLoader;
    use MockeryPHPUnitIntegration;
    use SummaryFixtures;

    private FakeClock $clock;
    private FakeHttpClient $http;
    private RecordingLogger $logger;
    private Repository $repository;

    /** @var list<string> */
    private array $enqueued = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        DetailRenderer::resetInstances();
        $this->stubEscapers();
        Functions\when('plugins_url')->justReturn('https://site.test/detail.css');
        Functions\when('wp_style_is')->justReturn(true);
        $this->enqueued = [];
        Functions\when('wp_enqueue_style')->alias(function (string $handle): void {
            $this->enqueued[] = $handle;
        });

        $this->clock = new FakeClock(gmmktime(15, 0, 0, 10, 26, 2026));
        $this->http = new FakeHttpClient();
        $this->logger = new RecordingLogger();
        $this->repository = new Repository($this->http, $this->clock, new FakeCache($this->clock), new FakeScheduler(), logger: $this->logger);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function shortcode(?Clock $clock = null): DetailShortcode
    {
        return new DetailShortcode($this->repository, $clock ?? $this->clock, new DetailRenderer(), $this->logger, Assets::detail());
    }

    /** @param list<string> $regions */
    private function warmCache(array $regions = ['nuble', 'biobio', 'araucania']): void
    {
        foreach ($regions as $region) {
            foreach (Dataset::cases() as $dataset) {
                $this->http->queue(new HttpResponse(200, self::fixture("nofires/{$dataset->value}-$region.json")));
                $this->repository->refresh($region, $dataset);
            }
        }
        $this->logger->messages = [];
        $this->http->calls = [];
    }

    public function test_it_registers_the_shortcode_tag(): void
    {
        Functions\expect('add_shortcode')->once()->with('cat_datos_detalle', \Mockery::type('callable'));

        $this->shortcode()->register();
    }

    public function test_with_warm_data_it_renders_three_tables_without_any_request(): void
    {
        $this->warmCache();

        $x = $this->xpath($this->shortcode()->render());

        $this->assertSame(3, $x->query('//table')->length);
        $this->assertSame(33, $x->query('(//section[contains(@class,"cat-detail__panel")])[2]//tbody/tr')->length);
        $this->assertSame([], $this->http->calls);
        $this->assertSame([], $this->logger->messages);
    }

    public function test_it_enqueues_only_the_detail_stylesheet(): void
    {
        $this->warmCache();

        $this->shortcode()->render();

        $this->assertSame(['cat-datos-detail'], $this->enqueued);
    }

    public function test_a_cold_cache_makes_at_most_one_request_and_still_renders_three_panels(): void
    {
        $html = $this->shortcode()->render();

        $this->assertLessThanOrEqual(1, count($this->http->calls));
        $x = $this->xpath($html);
        $this->assertSame(3, $x->query('//section[contains(@class,"cat-detail__panel")]')->length);
        $this->assertSame(0, $x->query('//table')->length);
        $this->assertStringContainsString('Datos no disponibles por el momento', $html);
    }

    public function test_a_region_without_data_does_not_change_the_others(): void
    {
        $this->warmCache(['nuble', 'araucania']);

        $x = $this->xpath($this->shortcode()->render());

        $this->assertSame(2, $x->query('//table')->length);
        $this->assertSame(1, $x->query('//p[contains(@class,"cat-detail__empty")]')->length);
        $this->assertSame(1, $x->query('(//section[contains(@class,"cat-detail__panel")])[2]//p[contains(@class,"cat-detail__empty")]')->length);
    }

    public function test_attributes_content_and_tag_are_ignored(): void
    {
        $this->warmCache();
        $plain = $this->shortcode()->render();
        DetailRenderer::resetInstances();

        $this->assertSame($plain, $this->shortcode()->render(['region' => '<script>alert(1)</script>'], 'inner', 'cat_datos_detalle'));
        DetailRenderer::resetInstances();
        $this->assertSame($plain, $this->shortcode()->render(''));
    }

    public function test_a_throwing_dependency_degrades_to_three_unavailable_panels_and_is_logged(): void
    {
        $clock = new class implements Clock {
            public function now(): int
            {
                throw new \RuntimeException('clock exploded');
            }
        };

        $x = $this->xpath($this->shortcode($clock)->render());

        $this->assertSame(3, $x->query('//p[contains(@class,"cat-detail__empty")]')->length);
        $this->assertSame(['detail shortcode: unexpected RuntimeException: clock exploded'], $this->logger->messages);
    }

    public function test_a_failing_stylesheet_never_blocks_the_markup(): void
    {
        $this->warmCache();
        Functions\when('wp_enqueue_style')->alias(static function (): void {
            throw new \RuntimeException('no assets today');
        });

        $html = $this->shortcode()->render();

        $this->assertStringContainsString('<table', $html);
        $this->assertSame(['detail shortcode: stylesheet failed: RuntimeException: no assets today'], $this->logger->messages);
    }

    public function test_two_instances_in_one_request_do_not_duplicate_ids(): void
    {
        $this->warmCache();

        $first = $this->shortcode()->render();
        $second = $this->shortcode()->render();

        $ids = array_map(static fn ($n) => $n->nodeValue, iterator_to_array($this->xpath('<div>' . $first . $second . '</div>')->query('//@id')));
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    // ---- wiring ------------------------------------------------------------------------------

    public function test_the_plugin_registers_the_detail_shortcode_on_init(): void
    {
        Actions\expectAdded('init')->once()->with([Plugin::class, 'registerDetailShortcode']);
        Functions\expect('add_shortcode')->never();

        Plugin::boot();
    }

    public function test_register_detail_shortcode_binds_the_tag(): void
    {
        Functions\expect('add_shortcode')->once()->with('cat_datos_detalle', \Mockery::type('callable'));

        Plugin::registerDetailShortcode();
    }

    // ---- public API v1 stays as it was --------------------------------------------------------

    public function test_the_public_api_v1_shapes_are_unchanged(): void
    {
        $this->warmCache(['biobio']);

        $summary = Api::summary($this->repository, $this->clock, 'biobio');
        $comunas = Api::comunas($this->repository, 'biobio');

        $this->assertSame(
            ['region', 'label', 'state', 'firmsTotal', 'firmsMissing', 'levelCounts', 'unclassifiedCount', 'comunaCount', 'criticalComunas', 'burned', 'freshnessAt', 'freshnessAtLocal', 'ageSeconds', 'sources'],
            array_keys($summary),
        );
        $this->assertSame(['comunaId', 'nombre', 'alertLevel', 'alertDriver', 'score', 'firmsCount', 'computedAtLocal'], array_keys($comunas[0]));
        $this->assertArrayNotHasKey('burned', $comunas[0]);
    }
}
