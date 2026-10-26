<?php

declare(strict_types=1);

namespace CableATierra\Datos\Wp;

use CableATierra\Datos\Methodology\Facts;
use CableATierra\Datos\Port\Logger;
use CableATierra\Datos\Render\Templates;

/**
 * [cat_datos_metodologia]: the static explanation of the alert rules. It has no repository, HTTP
 * client or cache, so it cannot depend on the API being up. Takes no attributes, enqueues only the
 * shared stylesheet (never a script) and never throws.
 */
final class MethodologyShortcode
{
    public const TAG = 'cat_datos_metodologia';

    public function __construct(
        private readonly Logger $logger,
        private readonly Assets $assets,
        private readonly Templates $templates = new Templates(),
    ) {
    }

    public function register(): void
    {
        add_shortcode(self::TAG, [$this, 'render']);
    }

    /** @param array<string, mixed>|string $attributes Ignored on purpose. */
    public function render(array|string $attributes = [], ?string $content = null, string $tag = ''): string
    {
        try {
            $this->assets->enqueue();
        } catch (\Throwable $e) {
            $this->warn('methodology shortcode: stylesheet failed: ' . $e::class . ': ' . $e->getMessage());
        }

        try {
            return $this->templates->render('methodology', Facts::view());
        } catch (\Throwable $e) {
            $this->warn('methodology shortcode: render failed: ' . $e::class . ': ' . $e->getMessage());

            return '<section class="cat-detail cat-detail--method alignwide" id="metodologia" aria-labelledby="metodologia-title"><h2 class="cat-detail__title" id="metodologia-title">Cómo se calcula</h2><p>La metodología no está disponible por el momento.</p></section>';
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
