<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Render;

use Brain\Monkey;
use CableATierra\Datos\Render\DetailRenderer;
use CableATierra\Datos\Tests\Support\DetailHtml;
use CableATierra\Datos\Tests\Support\SummaryFixtures;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** How numbers and labels read in the table cells (es-CL, no locale or intl involved). */
final class DetailFormatTest extends TestCase
{
    use DetailHtml;
    use MockeryPHPUnitIntegration;
    use SummaryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        DetailRenderer::resetInstances();
        $this->stubEscapers();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Text of column $column (1-based among the td cells) for a single comuna. */
    private function cell(?int $firms, ?float $ha, int $column): string
    {
        $d = $this->detail('biobio', [self::row('a', 'ALTO', $firms, null, self::FRESH, 'A')], self::burned(['a' => self::ha($ha)]));
        $x = $this->xpath((new DetailRenderer())->render([$d]));

        return $this->text($x, '//tbody/tr/td[' . $column . ']');
    }

    /** @return array<string, array{?int, string}> */
    public static function focos(): array
    {
        return ['thousands' => [1234, '1.234'], 'zero' => [0, '0'], 'missing is not zero' => [null, '—']];
    }

    #[DataProvider('focos')]
    public function test_focos_use_dots_for_thousands_and_a_dash_when_unknown(?int $firms, string $text): void
    {
        $this->assertSame($text, $this->cell($firms, 1.0, 3));
    }

    /** @return array<string, array{float, string}> */
    public static function hectares(): array
    {
        return [
            'half up' => [12345.5, '12.346'],
            'millions' => [1234567.4, '1.234.567'],
            'real zero' => [0.0, '0'],
        ];
    }

    #[DataProvider('hectares')]
    public function test_burned_area_is_rounded_half_up_with_the_hectare_unit(float $ha, string $number): void
    {
        $this->assertSame($number . self::NBSP . 'ha', $this->cell(1, $ha, 4));
    }

    public function test_a_fraction_of_a_hectare_reads_less_than_one_never_zero(): void
    {
        $this->assertSame('<1' . self::NBSP . 'ha', $this->cell(1, 0.4, 4));
    }

    public function test_the_burned_header_carries_the_layer_year(): void
    {
        $d = $this->detail('biobio', null, self::burned(['biobio1' => self::ha(1.0)], 2024));
        $x = $this->xpath((new DetailRenderer())->render([$d]));

        $this->assertSame('Superficie quemada 2024', $this->text($x, '//thead/tr/th[5]'));
    }

    public function test_numeric_columns_are_aligned_with_a_class_not_inline_style(): void
    {
        $x = $this->xpath((new DetailRenderer())->render([$this->detail('biobio')]));

        $this->assertSame(2, $x->query('//thead/tr/th[contains(@class,"cat-detail__num")]')->length);
        $this->assertSame(4, $x->query('//tbody/tr/td[contains(@class,"cat-detail__num")]')->length);
        $this->assertSame(0, $x->query('//@style')->length);
    }
}
