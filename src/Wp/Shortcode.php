<?php

declare(strict_types=1);

namespace CableATierra\Datos\Wp;

use CableATierra\Datos\Data\Repository;
use CableATierra\Datos\Domain\Dataset;
use CableATierra\Datos\Domain\Region;
use CableATierra\Datos\Port\Clock;
use CableATierra\Datos\Port\Logger;
use CableATierra\Datos\Render\BandRenderer;
use CableATierra\Datos\Summary\RegionSummarizer;

/**
 * [cat_datos_home_band]: the whole home band section. Takes no attributes (anything passed is
 * ignored) and never throws: a failure renders the "unavailable" band and is logged.
 */
final class Shortcode
{
    public const TAG = 'cat_datos_home_band';

    public function __construct(
        private readonly Repository $repository,
        private readonly Clock $clock,
        private readonly BandRenderer $renderer,
        private readonly Logger $logger,
        private readonly Assets $assets,
    ) {
    }

    public function register(): void
    {
        add_shortcode(self::TAG, [$this, 'render']);
    }

    /** @param array<string, mixed>|string $attributes Ignored on purpose: the band has no options. */
    public function render(array|string $attributes = [], ?string $content = null, string $tag = ''): string
    {
        try {
            $this->enqueueStyles();

            $now = $this->clock->now();
            $summarizer = new RegionSummarizer();
            $summaries = [];
            foreach (Region::cases() as $region) {
                $summaries[] = $summarizer->summarize(
                    $region->value,
                    $this->repository->read($region->value, Dataset::Risk),
                    $this->repository->read($region->value, Dataset::Burned),
                    $now,
                );
            }

            return $this->renderer->render($summaries, $now);
        } catch (\Throwable $e) {
            $this->warn('shortcode: unexpected ' . $e::class . ': ' . $e->getMessage());

            return $this->renderer->unavailable();
        }
    }

    private function enqueueStyles(): void
    {
        try {
            $this->assets->enqueue();
        } catch (\Throwable $e) {
            $this->warn('shortcode: stylesheet failed: ' . $e::class . ': ' . $e->getMessage());
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
