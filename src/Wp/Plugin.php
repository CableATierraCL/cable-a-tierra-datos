<?php

declare(strict_types=1);

namespace CableATierra\Datos\Wp;

use CableATierra\Datos\Data\Repository;
use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Domain\Region;

/**
 * Composition root: wires the WordPress adapters into the core.
 */
final class Plugin
{
    private static ?Repository $repository = null;

    public static function boot(): void
    {
        add_action(Repository::REFRESH_HOOK, static function (): void {
            self::refreshAll(self::repository());
        });
    }

    /** Shared, lazily built so that booting stays free of work. */
    public static function repository(): Repository
    {
        $clock = new SystemClock();

        return self::$repository ??= new Repository(new WpHttpClient(), $clock, new WpCache(), new WpScheduler($clock));
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
