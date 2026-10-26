<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/** Guards the detail stylesheet contract: budget, scope, container-relative internals, contrast, print. */
final class DetailCssTest extends TestCase
{
    private const INK = '#10261F';
    private const PAPER = '#F3F1EB';

    private string $css = '';

    protected function setUp(): void
    {
        parent::setUp();
        $path = CAT_DATOS_DIR . 'assets/css/detail.css';
        $this->assertFileExists($path);
        $this->css = (string) file_get_contents($path);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(static function (string $c): float {
            $v = hexdec($c) / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private static function contrast(string $a, string $b): float
    {
        [$hi, $lo] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    /** @return string Body of the `@media print` block, or '' when absent. */
    private function printBlock(): string
    {
        $start = strpos($this->css, '@media print');
        if ($start === false) {
            return '';
        }
        $depth = 0;
        $open = (int) strpos($this->css, '{', $start);
        for ($i = $open, $len = strlen($this->css); $i < $len; $i++) {
            $depth += $this->css[$i] === '{' ? 1 : ($this->css[$i] === '}' ? -1 : 0);
            if ($depth === 0) {
                return substr($this->css, $open + 1, $i - $open - 1);
            }
        }

        return '';
    }

    public function test_it_stays_within_the_size_budget(): void
    {
        $this->assertLessThanOrEqual(4096, strlen($this->css), 'raw budget is 4 KB');
    }

    public function test_it_never_uses_important_and_is_static(): void
    {
        $this->assertStringNotContainsStringIgnoringCase('!important', $this->css);
        $this->assertDoesNotMatchRegularExpression('/\b(transition|animation|@keyframes|scroll-behavior)\b/', $this->css);
    }

    public function test_every_selector_is_scoped_to_the_detail_vocabulary(): void
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $this->css);
        preg_match_all('/([^{}@]+)\{[^{}]*\}/', $css, $m);
        $this->assertNotSame([], $m[1]);
        foreach ($m[1] as $group) {
            foreach (explode(',', $group) as $selector) {
                $this->assertMatchesRegularExpression('/^\.cat-detail/', trim($selector), 'unscoped selector: ' . trim($selector));
                $this->assertStringNotContainsString('#', $selector);
            }
        }
    }

    public function test_it_styles_every_class_the_markup_uses(): void
    {
        foreach ([
            'cat-detail', 'cat-detail__nav', 'cat-detail__panel', 'cat-detail__title', 'cat-detail__asof', 'cat-detail__flag',
            'cat-detail__coverage', 'cat-detail__empty', 'cat-detail__scroll', 'cat-detail__table', 'cat-detail__num',
            'cat-detail__sw', 'cat-detail__sw--critico', 'cat-detail__sw--alto', 'cat-detail__sw--preventivo', 'cat-detail__sw--normal',
            'cat-detail__foot', 'cat-detail__cite', 'cat-detail__sub', 'cat-detail__levels', 'cat-detail__terms', 'cat-detail__list',
        ] as $class) {
            $this->assertStringContainsString('.' . $class, $this->css, "missing .$class");
        }
    }

    public function test_it_sizes_internals_to_the_container_and_never_to_the_viewport(): void
    {
        $this->assertDoesNotMatchRegularExpression('/@media[^{]*(min|max)-width/', $this->css);
        $this->assertDoesNotMatchRegularExpression('/\b\d+(\.\d+)?v[wh]\b/', $this->css);
        $this->assertMatchesRegularExpression('/\.cat-detail__scroll\{[^}]*overflow-x:auto/', $this->css);
        $this->assertMatchesRegularExpression('/\.cat-detail__nav\{[^}]*flex-wrap:wrap/', $this->css);
        $this->assertMatchesRegularExpression('/\.cat-detail\{[^}]*grid-template-columns:minmax\(0,1fr\)/', $this->css);
    }

    public function test_numeric_columns_are_right_aligned_with_tabular_figures(): void
    {
        $this->assertMatchesRegularExpression('/\.cat-detail__num\{[^}]*text-align:right/', $this->css);
        $this->assertMatchesRegularExpression('/\.cat-detail__num\{[^}]*font-variant-numeric:tabular-nums/', $this->css);
    }

    public function test_the_first_column_is_sticky_on_the_paper_colour(): void
    {
        $this->assertMatchesRegularExpression('/\.cat-detail__table th\[scope="row"\]\{[^}]*position:sticky[^}]*left:0[^}]*background:var\(--cat-paper\)/', $this->css);
    }

    public function test_sticky_cells_keep_their_borders_with_a_separate_border_model(): void
    {
        $this->assertMatchesRegularExpression('/\.cat-detail__table\{[^}]*border-collapse:separate[^}]*border-spacing:0/', $this->css);
        $this->assertDoesNotMatchRegularExpression('/border-collapse:collapse/', $this->css);
    }

    public function test_the_corner_header_cell_is_sticky_above_the_body_cells(): void
    {
        $this->assertMatchesRegularExpression('/\.cat-detail__table thead th:first-child\{[^}]*position:sticky[^}]*left:0[^}]*background:var\(--cat-paper\)[^}]*z-index:2/', $this->css);
        $this->assertMatchesRegularExpression('/th\[scope="row"\]\{[^}]*z-index:1/', $this->css);
    }

    public function test_the_sticky_column_wraps_and_is_capped_so_other_columns_stay_visible(): void
    {
        $this->assertMatchesRegularExpression('/th\[scope="row"\]\{[^}]*white-space:normal/', $this->css);
        $this->assertDoesNotMatchRegularExpression('/white-space:nowrap/', $this->css);
        $this->assertMatchesRegularExpression('/th\[scope="row"\]\{[^}]*max-width:min\(12rem,40%\)/', $this->css);
    }

    public function test_the_scroll_region_shows_a_focus_ring(): void
    {
        $this->assertMatchesRegularExpression('/\.cat-detail__scroll:focus-visible\{[^}]*outline:2px solid/', $this->css);
    }

    public function test_text_uses_ink_and_a_muted_tone_that_reach_aa_on_paper(): void
    {
        $this->assertMatchesRegularExpression('/--cat-ink:var\(--wp--preset--color--ink,#10261F\)/i', $this->css);
        $this->assertMatchesRegularExpression('/--cat-paper:var\(--wp--preset--color--paper,#F3F1EB\)/i', $this->css);
        $this->assertDoesNotMatchRegularExpression('/var\(--wp--[^,)]+\)/', $this->css, 'a theme variable without fallback');
        preg_match('/--cat-muted:(#[0-9a-f]{6})/i', $this->css, $muted);
        $this->assertGreaterThanOrEqual(4.5, self::contrast($muted[1] ?? '#ffffff', self::PAPER));
        $this->assertGreaterThanOrEqual(4.5, self::contrast(self::INK, self::PAPER));
        $this->assertMatchesRegularExpression('/--cat-rule:#D4D1C6/i', $this->css);
    }

    public function test_ember_and_ochre_only_fill_swatches_and_never_colour_text(): void
    {
        $this->assertDoesNotMatchRegularExpression('/(?<![-\w])color:[^;}]*(ember|ochre|F26B4B|D9A441)/i', $this->css);
        $this->assertMatchesRegularExpression('/\.cat-detail__sw--alto\{[^}]*repeating-linear-gradient/', $this->css);
        $this->assertMatchesRegularExpression('/\.cat-detail__sw--preventivo\{[^}]*radial-gradient/', $this->css);
        $this->assertMatchesRegularExpression('/\.cat-detail__sw--normal\{[^}]*box-shadow:inset/', $this->css);
        $this->assertMatchesRegularExpression('/\.cat-detail__sw\{[^}]*border:1px solid var\(--cat-ink\)/', $this->css);
    }

    public function test_print_drops_the_scroll_and_the_sticky_column_and_hides_the_nav(): void
    {
        $print = $this->printBlock();

        $this->assertNotSame('', $print, 'a @media print block is required');
        $this->assertMatchesRegularExpression('/\.cat-detail__nav\{[^}]*display:none/', $print);
        $this->assertMatchesRegularExpression('/\.cat-detail__scroll\{[^}]*overflow:visible/', $print);
        $this->assertMatchesRegularExpression('/\.cat-detail__table th\[scope="row"\][^{]*\{[^}]*position:static/', $print);
        $this->assertMatchesRegularExpression('/thead th:first-child\{[^}]*position:static/', $print);
        $this->assertMatchesRegularExpression('/\.cat-detail__sw\{[^}]*print-color-adjust:exact/', $print);
        $this->assertDoesNotMatchRegularExpression('/display:none[^}]*\}[^{]*\.cat-detail__panel/', $print, 'all panels print');
    }
}
