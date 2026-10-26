<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/** Guards the stylesheet contract from the UX design: budget, scope, no overrides, contrast. */
final class BandCssTest extends TestCase
{
    private const INK = '#10261F';

    private string $css = '';

    protected function setUp(): void
    {
        parent::setUp();
        $path = CAT_DATOS_DIR . 'assets/css/band.css';
        $this->assertFileExists($path);
        $this->css = (string) file_get_contents($path);
    }

    /** @return list<string> Selectors of every rule, including those nested in @media. */
    private function selectors(): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $this->css);
        preg_match_all('/([^{}@]+)\{[^{}]*\}/', $css, $m);
        $selectors = [];
        foreach ($m[1] as $group) {
            foreach (explode(',', $group) as $selector) {
                $selectors[] = trim($selector);
            }
        }

        return $selectors;
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

    public function test_it_stays_within_the_size_budget(): void
    {
        $this->assertLessThanOrEqual(6144, strlen($this->css), 'raw budget is 6 KB');
        $this->assertLessThanOrEqual(2048, strlen((string) gzencode($this->css, 9)), 'gzip budget is 2 KB');
    }

    public function test_it_never_uses_important(): void
    {
        $this->assertStringNotContainsStringIgnoringCase('!important', $this->css);
    }

    public function test_every_selector_is_scoped_to_the_band_vocabulary(): void
    {
        $selectors = $this->selectors();

        $this->assertNotSame([], $selectors);
        foreach ($selectors as $selector) {
            $this->assertMatchesRegularExpression('/^\.cat-(band|region|bar|legend|level--)/', $selector, "unscoped selector: $selector");
            $this->assertStringNotContainsString('#', $selector, "no id selectors: $selector");
        }
    }

    public function test_it_styles_every_class_the_markup_uses(): void
    {
        $classes = [
            'cat-band', 'cat-band__inner', 'cat-band__head', 'cat-band__summary', 'cat-band__regions', 'cat-band__note',
            'cat-band__foot', 'cat-band__cite', 'cat-band__sr', 'cat-band__empty',
            'cat-region', 'cat-region__head', 'cat-region__name', 'cat-region__flag', 'cat-region__asof', 'cat-region__empty',
            'cat-region__na', 'cat-region__stat', 'cat-region__stats--secondary', 'cat-region__dist', 'cat-region__dist-title',
            'cat-bar', 'cat-bar__seg', 'cat-legend', 'cat-legend__item', 'cat-legend__key', 'cat-legend__n',
            'cat-level--critico', 'cat-level--alto', 'cat-level--preventivo', 'cat-level--normal',
        ];
        foreach ($classes as $class) {
            $this->assertStringContainsString('.' . $class, $this->css, "missing .$class");
        }
    }

    public function test_it_stacks_below_the_theme_breakpoint_and_is_three_up_above_it(): void
    {
        $this->assertStringContainsString('@media (max-width:960px)', $this->css);
        $this->assertMatchesRegularExpression('/\.cat-band__regions\{[^}]*repeat\(3,minmax\(0,1fr\)\)/', $this->css);
    }

    /** @return string Body of the `@media print` block (nested braces included), or '' when absent. */
    private function printBlock(): string
    {
        $start = strpos($this->css, '@media print');
        if ($start === false) {
            return '';
        }
        $depth = 0;
        $open = strpos($this->css, '{', $start);
        for ($i = (int) $open, $len = strlen($this->css); $i < $len; $i++) {
            $depth += $this->css[$i] === '{' ? 1 : ($this->css[$i] === '}' ? -1 : 0);
            if ($depth === 0) {
                return substr($this->css, (int) $open + 1, $i - (int) $open - 1);
            }
        }

        return '';
    }

    public function test_print_turns_the_light_on_dark_palette_into_dark_text_on_white(): void
    {
        $print = $this->printBlock();

        $this->assertNotSame('', $print, 'a @media print block is required');
        $this->assertMatchesRegularExpression('/--cat-paper:#[0-9a-f]{3,6}\b/i', $print);
        $this->assertMatchesRegularExpression('/--cat-label:#[0-9a-f]{3,6}\b/i', $print);
        $this->assertMatchesRegularExpression('/--cat-note:#[0-9a-f]{3,6}\b/i', $print);
        foreach (['paper', 'label', 'note'] as $token) {
            preg_match('/--cat-' . $token . ':#([0-9a-f]{3})\b/i', $print, $m);
            $hex = isset($m[1]) ? '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2] : '#ffffff';
            $this->assertGreaterThanOrEqual(7.0, self::contrast($hex, '#ffffff'), "print --cat-$token on white");
        }
    }

    public function test_print_keeps_borders_visible_and_forces_bar_colours_to_print(): void
    {
        $print = $this->printBlock();

        $this->assertMatchesRegularExpression('/\.cat-region\{[^}]*border-top-color:#[0-9a-f]{3,6}/i', $print);
        $this->assertMatchesRegularExpression('/\.cat-bar__seg,\.cat-legend__key\{(?:[^}]*;)?print-color-adjust:exact/', $print);
        $this->assertMatchesRegularExpression('/\.cat-bar__seg,\.cat-legend__key\{[^}]*-webkit-print-color-adjust:exact/', $print);
        $this->assertMatchesRegularExpression('/\.cat-band__note a,\.cat-band__foot a\{[^}]*color:#[0-9a-f]{3,6}/i', $print);
    }

    /**
     * The band can sit in containers narrower than the viewport (a page body, a column), so the
     * card internals must fit their own width: viewport media queries cannot size the legend.
     */
    public function test_the_distribution_track_shrinks_to_the_card_instead_of_its_content(): void
    {
        $this->assertMatchesRegularExpression('/\.cat-region__dist\{[^}]*grid-template-columns:minmax\(0,1fr\)/', $this->css);
    }

    public function test_the_legend_wraps_whole_items_instead_of_using_fixed_columns(): void
    {
        $this->assertMatchesRegularExpression('/\.cat-legend\{[^}]*display:flex[^}]*flex-wrap:wrap/', $this->css);
        $this->assertMatchesRegularExpression('/\.cat-legend__item\{[^}]*white-space:nowrap/', $this->css);
        $this->assertDoesNotMatchRegularExpression('/\.cat-legend\{[^}]*grid-template-columns/', $this->css);
    }

    public function test_the_stylesheet_is_static_so_reduced_motion_needs_no_special_case(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\b(transition|animation|@keyframes|scroll-behavior)\b/', $this->css);
    }

    public function test_theme_variables_always_carry_a_hex_fallback(): void
    {
        $this->assertMatchesRegularExpression('/var\(--wp--preset--color--ember,#F26B4B\)/i', $this->css);
        $this->assertDoesNotMatchRegularExpression('/var\(--wp--[^,)]+\)/', $this->css, 'a theme variable without fallback');
    }

    public function test_the_nonzero_bar_segments_keep_a_minimum_width_and_zero_ones_collapse(): void
    {
        $this->assertMatchesRegularExpression('/\.cat-bar__seg\{[^}]*flex:var\(--n\) 1 0/', $this->css);
        $this->assertMatchesRegularExpression('/\.cat-bar__seg:not\(\[style\*="--n:0"\]\)\{[^}]*min-width:6px/', $this->css);
    }

    public function test_level_fills_use_pattern_or_outline_and_not_colour_alone(): void
    {
        $this->assertMatchesRegularExpression('/\.cat-level--alto\{[^}]*repeating-linear-gradient/', $this->css);
        $this->assertMatchesRegularExpression('/\.cat-level--preventivo\{[^}]*radial-gradient/', $this->css);
        $this->assertMatchesRegularExpression('/\.cat-level--normal\{[^}]*box-shadow:inset 0 0 0 2px/', $this->css);
    }

    public function test_text_colours_reach_aa_on_the_band_ink_and_level_fills_reach_non_text_contrast(): void
    {
        // Text colours are the --cat-paper/label/note tokens (hex or var with hex fallback) plus any literal `color:`.
        preg_match_all('/(?:--cat-(?:paper|label|note)|(?<![-\w])color):\s*(?:var\([^,)]+,\s*)?(#[0-9a-f]{6})\b/i', $this->css, $text);
        $this->assertCount(3, array_unique($text[1]), 'paper, label and note');
        foreach (array_unique($text[1]) as $hex) {
            $this->assertGreaterThanOrEqual(4.5, self::contrast($hex, self::INK), "text colour $hex");
        }

        preg_match_all('/--cat-level-[a-z]+:[^;]*?,\s*(#[0-9a-f]{6})\)?/i', $this->css, $fills);
        preg_match_all('/--cat-level-normal:\s*(#[0-9a-f]{6})/i', $this->css, $normal);
        $levelHex = array_unique(array_merge($fills[1], $normal[1]));
        $this->assertCount(4, $levelHex);
        $this->assertGreaterThanOrEqual(4.5, self::contrast('#D9A441', self::INK), 'ochre is also used for the stale chip text');
        foreach ($levelHex as $hex) {
            $this->assertGreaterThanOrEqual(3.0, self::contrast($hex, self::INK), "level colour $hex");
        }
    }

    public function test_links_in_the_band_get_a_visible_focus_ring_and_a_comfortable_target(): void
    {
        $this->assertMatchesRegularExpression('/\.cat-band a:focus-visible\{[^}]*outline:2px solid/', $this->css);
        $this->assertMatchesRegularExpression('/\.cat-band__foot a\{[^}]*padding-block:\.25rem/', $this->css);
    }

    public function test_the_visually_hidden_class_hides_without_display_none(): void
    {
        $this->assertMatchesRegularExpression('/\.cat-band__sr\{[^}]*clip-path:inset\(50%\)/', $this->css);
        $this->assertDoesNotMatchRegularExpression('/\.cat-band__sr\{[^}]*display:none/', $this->css);
    }
}
