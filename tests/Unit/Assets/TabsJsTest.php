<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/** Static guards for the plain browser script: budget, scope, and the no-hijack / no-focus-steal rules. */
final class TabsJsTest extends TestCase
{
    private string $js = '';

    protected function setUp(): void
    {
        parent::setUp();
        $path = CAT_DATOS_DIR . 'assets/js/tabs.js';
        $this->assertFileExists($path);
        $this->js = (string) file_get_contents($path);
    }

    /** @return string Body of `function name(...) { ... }`, balanced, or ''. */
    private function body(string $name): string
    {
        if (preg_match('/function ' . preg_quote($name, '/') . '\([^)]*\)\s*\{/', $this->js, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }
        $open = $m[0][1] + strlen($m[0][0]) - 1;
        $depth = 0;
        for ($i = $open, $len = strlen($this->js); $i < $len; $i++) {
            $depth += $this->js[$i] === '{' ? 1 : ($this->js[$i] === '}' ? -1 : 0);
            if ($depth === 0) {
                return substr($this->js, $open + 1, $i - $open - 1);
            }
        }

        return '';
    }

    public function test_it_stays_within_the_size_budget(): void
    {
        $this->assertLessThanOrEqual(3584, strlen($this->js), 'raw budget is 3.5 KB');
    }

    public function test_it_is_one_iife_with_no_top_level_globals(): void
    {
        $js = trim((string) preg_replace('~^\s*/\*.*?\*/\s*|^(\s*//[^\n]*\n)+~s', '', $this->js));

        $this->assertMatchesRegularExpression('/^\(function \(\) \{/', $js);
        $this->assertMatchesRegularExpression('/\}\)\(\);\s*$/', $js);
        $this->assertDoesNotMatchRegularExpression('/\b(window|globalThis|self)\.[A-Za-z_$]+\s*=/', $this->js);
        $this->assertDoesNotMatchRegularExpression('/\b(import|export)\b\s/', $this->js, 'a plain script, not an ES module');
    }

    public function test_pure_helpers_reach_node_only_through_the_module_guard(): void
    {
        $this->assertMatchesRegularExpression(
            "/typeof document === 'undefined'\\) \\{\\s*if \\(typeof module !== 'undefined' && module\\.exports\\)/",
            $this->js,
            'export only when there is no DOM'
        );
        $this->assertLessThan(strpos($this->js, 'module.exports'), strpos($this->js, "typeof document === 'undefined'"));
        $this->assertSame(1, substr_count($this->js, 'document.'), 'the only DOM access is the final root lookup');
        $this->assertMatchesRegularExpression('/module\.exports = \{[^}]*selectFromHash[^}]*nextIndex[^}]*canEnhance[^}]*enhance/', $this->js);
    }

    public function test_it_updates_the_url_without_navigating_or_scrolling(): void
    {
        $this->assertStringContainsString('history.replaceState', $this->js);
        $this->assertDoesNotMatchRegularExpression('/location\.(hash|href|assign|replace)\s*=|location\.(assign|replace)\(/', $this->js);
    }

    public function test_the_hashchange_path_never_prevents_default_nor_focuses(): void
    {
        $this->assertMatchesRegularExpression("/addEventListener\\('hashchange', sync\\)/", $this->js);
        $sync = $this->body('sync');
        $this->assertNotSame('', $sync, 'sync() must exist');
        $this->assertStringNotContainsString('preventDefault', $sync);
        $this->assertStringNotContainsString('.focus(', $sync);
        $this->assertStringNotContainsString('preventDefault', $this->body('selectFromHash'));
    }

    public function test_focus_is_only_ever_moved_by_the_keydown_handler(): void
    {
        $this->assertSame(1, substr_count($this->js, '.focus('));
        $this->assertGreaterThan(strpos($this->js, "'keydown'"), strpos($this->js, '.focus('));
    }

    public function test_it_ignores_browser_shortcuts_and_non_primary_clicks(): void
    {
        $this->assertMatchesRegularExpression('/altKey[^;]*ctrlKey[^;]*metaKey[^;]*shiftKey/', $this->body('modified'));
        $this->assertStringContainsString('e.button !== 0', $this->js);
        $this->assertStringContainsString('e.defaultPrevented', $this->js);
        $this->assertStringContainsString('nextIndex(i, e.key, tabs.length)', $this->js);
        $this->assertStringNotContainsString('is-tabs', $this->js);
    }

    public function test_it_only_enhances_the_server_markup_it_finds(): void
    {
        $this->assertStringContainsString('[data-cat-tabs]', $this->js);
        $this->assertStringContainsString('data-region', $this->js);
        $this->assertStringContainsString('canEnhance(', $this->js);
        foreach (['tablist', 'tab', 'tabpanel', 'aria-selected', 'aria-controls', 'aria-labelledby', 'tabindex'] as $needle) {
            $this->assertStringContainsString($needle, $this->js, $needle);
        }
    }

    public function test_it_has_no_dependencies_and_no_network(): void
    {
        $this->assertDoesNotMatchRegularExpression('/\bjQuery\b|\$\(|\bfetch\(|XMLHttpRequest|\beval\(|innerHTML/', $this->js);
    }
}
