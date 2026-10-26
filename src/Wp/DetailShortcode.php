<?php

declare(strict_types=1);

namespace CableATierra\Datos\Wp;

use CableATierra\Datos\Data\Repository;
use CableATierra\Datos\Detail\RegionDetail;
use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Domain\Region;
use CableATierra\Datos\Port\Clock;
use CableATierra\Datos\Port\Logger;
use CableATierra\Datos\Render\DetailRenderer;

/**
 * [cat_datos_detalle]: per-comuna tables for the three regions. Takes no attributes (anything
 * passed is ignored) and never throws: a failure renders the unavailable panels and is logged.
 */
final class DetailShortcode
{
    public const TAG = 'cat_datos_detalle';

    public function __construct(
        private readonly Repository $repository,
        private readonly Clock $clock,
        private readonly DetailRenderer $renderer,
        private readonly Logger $logger,
        private readonly Assets $assets,
    ) {
    }

    public function register(): void
    {
        add_shortcode(self::TAG, [$this, 'render']);
    }

    /** @param array<string, mixed>|string $attributes Ignored on purpose: the tables have no options. */
    public function render(array|string $attributes = [], ?string $content = null, string $tag = ''): string
    {
        try {
            $this->enqueueStyles();

            $now = $this->clock->now();
            $details = [];
            foreach (Region::cases() as $region) {
                try {
                    $details[] = RegionDetail::build(
                        $region->value,
                        $this->repository->read($region->value, Dataset::Risk),
                        $this->repository->read($region->value, Dataset::Burned),
                        $now,
                    );
                } catch (\Throwable $e) {
                    // One region failing never changes another's panel.
                    $this->warn('detail shortcode: ' . $region->value . ' failed: ' . $e::class . ': ' . $e->getMessage());
                    $details[] = RegionDetail::unavailable($region->value);
                }
            }

            return $this->renderer->render($details);
        } catch (\Throwable $e) {
            $this->warn('detail shortcode: unexpected ' . $e::class . ': ' . $e->getMessage());

            return $this->renderer->unavailable();
        }
    }

    private function enqueueStyles(): void
    {
        try {
            $this->assets->enqueue();
        } catch (\Throwable $e) {
            $this->warn('detail shortcode: stylesheet failed: ' . $e::class . ': ' . $e->getMessage());
        }
    }

    private function warn(string $message): void
    {
        try {
            $this->logger->warning($message);
        } catch (\Throwable) {
            // Logging is best effort.
        }
    }
}
