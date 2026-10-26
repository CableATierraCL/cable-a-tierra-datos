<?php

declare(strict_types=1);

namespace CableATierra\Datos\Wp;

use CableATierra\Datos\Data\Repository;
use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Domain\Region;
use CableATierra\Datos\Render\BandRenderer;

/**
 * Composition root: wires the WordPress adapters into the core.
 */
final class Plugin
{
    private static ?Repository $repository = null;

    /** Hooks only; no I/O happens until a hook fires. */
    public static function boot(): void
    {
        add_action(Repository::REFRESH_HOOK, static function (): void {
            self::refreshAll(self::repository());
        });
        add_filter('cron_schedules', [Cron::class, 'addSchedule']);
        add_action('init', [self::class, 'registerShortcode']);
        add_action('wp_enqueue_scripts', [new Assets(), 'onEnqueueScripts']);
    }

    /** Must run at plugin load time (not on plugins_loaded), so the main file calls it directly. */
    public static function registerLifecycleHooks(string $pluginFile): void
    {
        register_activation_hook($pluginFile, [Cron::class, 'activate']);
        register_deactivation_hook($pluginFile, [Cron::class, 'deactivate']);
    }

    /** `init` callback. */
    public static function registerShortcode(): void
    {
        (new Shortcode(self::repository(), new SystemClock(), new BandRenderer(), self::logger(), new Assets()))->register();
    }

    /** Shared, lazily built so that booting stays free of work. */
    public static function repository(): Repository
    {
        $clock = new SystemClock();

        return self::$repository ??= new Repository(new WpHttpClient(), $clock, new WpCache(), new WpScheduler($clock), logger: self::logger());
    }

    private static function logger(): WpLogger
    {
        return new WpLogger(defined('WP_DEBUG') && WP_DEBUG);
    }

    /** Refreshes whatever is due for every region and dataset. Never throws. */
    public static function refreshAll(Repository $repository): void
    {
        foreach (Region::cases() as $region) {
            foreach (Dataset::cases() as $dataset) {
                $repository->refresh($region->value, $dataset);
            }
        }
    }
}
