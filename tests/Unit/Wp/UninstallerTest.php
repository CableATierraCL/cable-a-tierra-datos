<?php

declare(strict_types=1);

namespace CableATierra\Datos\Tests\Unit\Wp;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CableATierra\Datos\Data\Repository;
use CableATierra\Datos\Wp\Uninstaller;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class UninstallerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var list<string> */
    private array $deletedTransients = [];

    /** @var list<string> */
    private array $deletedOptions = [];

    /** @var list<string> */
    private array $clearedHooks = [];

    private mixed $previousWpdb = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        Functions\when('delete_transient')->alias(function (string $key): bool {
            $this->deletedTransients[] = $key;

            return true;
        });
        Functions\when('delete_option')->alias(function (string $key): bool {
            $this->deletedOptions[] = $key;

            return true;
        });
        Functions\when('wp_clear_scheduled_hook')->alias(function (string $hook): int {
            $this->clearedHooks[] = $hook;

            return 1;
        });
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->previousWpdb;
        Monkey\tearDown();
        parent::tearDown();
    }

    private function fakeWpdb(): object
    {
        return new class {
            public string $options = 'wp_options';

            /** @var list<string> */
            public array $queries = [];

            public function esc_like(string $text): string
            {
                return addcslashes($text, '_%\\');
            }

            public function prepare(string $query, string ...$args): string
            {
                return vsprintf(str_replace('%s', "'%s'", $query), $args);
            }

            public function query(string $query): int
            {
                $this->queries[] = $query;

                return 0;
            }
        };
    }

    public function test_it_deletes_every_known_transient_and_option_and_the_cron_hook(): void
    {
        $GLOBALS['wpdb'] = $this->fakeWpdb();

        Uninstaller::run();

        $this->assertSame(Repository::transientKeys(), $this->deletedTransients);
        $this->assertSame(Repository::optionKeys(), $this->deletedOptions);
        $this->assertSame(['cat_datos_refresh'], $this->clearedHooks);
    }

    public function test_the_sweep_only_touches_the_options_table_with_strictly_prefixed_escaped_patterns(): void
    {
        $wpdb = $this->fakeWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        Uninstaller::run();

        $this->assertCount(1, $wpdb->queries);
        $sql = $wpdb->queries[0];
        $this->assertStringStartsWith('DELETE FROM wp_options WHERE ', $sql);
        $this->assertSame(1, substr_count($sql, 'DELETE'));
        // esc_like escapes the underscores, so `_` cannot act as a wildcard.
        $this->assertStringContainsString("option_name LIKE 'cat\\_datos\\_v1\\_%'", $sql);
        $this->assertStringContainsString("option_name LIKE '\\_transient\\_cat\\_datos\\_v1\\_%'", $sql);
        $this->assertStringContainsString("option_name LIKE '\\_transient\\_timeout\\_cat\\_datos\\_v1\\_%'", $sql);
        $this->assertSame(3, substr_count($sql, 'LIKE'));
        $this->assertStringNotContainsString('site_transient', $sql);
    }

    public function test_it_survives_a_missing_database_handle(): void
    {
        $GLOBALS['wpdb'] = null;

        Uninstaller::run();

        $this->assertSame(['cat_datos_refresh'], $this->clearedHooks);
    }

    public function test_the_uninstall_script_does_nothing_unless_wordpress_is_uninstalling_the_plugin(): void
    {
        $script = CAT_DATOS_DIR . 'uninstall.php';
        $code = 'define("ABSPATH", "x/"); require ' . var_export($script, true) . '; echo "REACHED-END";';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($process);

        $this->assertStringNotContainsString('REACHED-END', $out);
        $this->assertStringNotContainsString('Fatal', $out . $err);
    }
}
