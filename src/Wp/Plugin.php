<?php

declare(strict_types=1);

namespace CableATierra\Datos\Wp;

use CableATierra\Datos\Data\Repository;
use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Domain\Region;
use CableATierra\Datos\Render\BandRenderer;
use CableATierra\Datos\Render\DetailRenderer;

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
        add_action('init', [self::class, 'registerDetailShortcode']);
        add_action('init', [self::class, 'registerMethodologyShortcode']);
        add_action('init', [self::class, 'ensureSchedule']);
        add_action('wp_enqueue_scripts', static function (): void {
            (new Assets())->onEnqueueScripts();
            Assets::detail()->onEnqueueScripts();
            (new TabsScript())->onEnqueueScripts();
        });
    }

    /**
     * `init` callback. Self-heals the recurring event (lost on a failed activation, a cron table
     * wipe or a deploy that skipped activation). Checks the schedule name, not wp_next_scheduled:
     * a queued single refresh event shares the hook and would hide a missing recurring one.
     */
    public static function ensureSchedule(): void
    {
        if (wp_installing() || wp_get_schedule(Repository::REFRESH_HOOK) === Cron::SCHEDULE) {
            return;
        }

        Cron::activate();
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

    /** `init` callback. */
    public static function registerDetailShortcode(): void
    {
        (new DetailShortcode(self::repository(), new SystemClock(), new DetailRenderer(), self::logger(), Assets::detail(), new TabsScript()))->register();
    }

    /** `init` callback. Static content: deliberately takes no repository. */
    public static function registerMethodologyShortcode(): void
    {
        (new MethodologyShortcode(self::logger(), Assets::detail()))->register();
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
