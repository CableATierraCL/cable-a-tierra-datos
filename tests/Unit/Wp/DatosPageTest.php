<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Wp;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CableATierra\Datos\Wp\DatosPage;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatosPageTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('has_shortcode')->alias(static fn (string $content, string $tag): bool => preg_match('/\[' . preg_quote($tag, '/') . '(\s[^\]]*)?\]/', $content) === 1);
        Functions\when('get_permalink')->alias(static fn (int $id): string => 'https://site.test/datos/');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function page(string $status, string $content): object
    {
        return (object) ['ID' => 1734, 'post_status' => $status, 'post_content' => $content];
    }

    public function test_absent_page_gives_empty_string(): void
    {
        Functions\expect('get_page_by_path')->once()->with('datos', 'OBJECT', 'page')->andReturn(null);

        $this->assertSame('', (new DatosPage())->url());
    }

    /** @return array<string, array{string}> */
    public static function unpublished(): array
    {
        return ['draft' => ['draft'], 'pending' => ['pending'], 'private' => ['private'], 'trash' => ['trash'], 'future' => ['future']];
    }

    #[DataProvider('unpublished')]
    public function test_a_page_that_is_not_published_gives_empty_string(string $status): void
    {
        Functions\when('get_page_by_path')->justReturn($this->page($status, '[cat_datos_detalle]'));

        $this->assertSame('', (new DatosPage())->url());
    }

    public function test_published_without_the_detail_shortcode_gives_empty_string(): void
    {
        Functions\when('get_page_by_path')->justReturn($this->page('publish', 'Hello world'));

        $this->assertSame('', (new DatosPage())->url());
    }

    public function test_methodology_shortcode_alone_does_not_open_the_gate(): void
    {
        Functions\when('get_page_by_path')->justReturn($this->page('publish', '[cat_datos_metodologia]'));

        $this->assertSame('', (new DatosPage())->url());
    }

    public function test_published_with_the_detail_shortcode_gives_the_permalink(): void
    {
        Functions\when('get_page_by_path')->justReturn($this->page('publish', 'Intro [cat_datos_detalle] [cat_datos_metodologia]'));

        $this->assertSame('https://site.test/datos/', (new DatosPage())->url());
    }

    public function test_shortcode_attributes_are_allowed(): void
    {
        Functions\when('get_page_by_path')->justReturn($this->page('publish', '[cat_datos_detalle foo="1"]'));

        $this->assertSame('https://site.test/datos/', (new DatosPage())->url());
    }

    public function test_the_lookup_runs_once_per_instance_even_when_the_answer_is_empty(): void
    {
        Functions\expect('get_page_by_path')->once()->andReturn(null);
        $page = new DatosPage();

        $this->assertSame('', $page->url());
        $this->assertSame('', $page->url());
    }

    public function test_a_non_string_permalink_gives_empty_string(): void
    {
        Functions\when('get_page_by_path')->justReturn($this->page('publish', '[cat_datos_detalle]'));
        Functions\when('get_permalink')->justReturn(false);

        $this->assertSame('', (new DatosPage())->url());
    }
}
