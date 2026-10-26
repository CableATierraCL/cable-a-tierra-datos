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

    /** Renders started in this request; drives unique ids. */
    private static int $renders = 0;

    /** @internal Test hook: restarts the per-request render count. */
    public static function resetInstances(): void
    {
        self::$renders = 0;
    }

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

        [$id, $titleId] = self::nextIds();
        try {
            return $this->templates->render('methodology', ['id' => $id, 'titleId' => $titleId] + Facts::view());
        } catch (\Throwable $e) {
            $this->warn('methodology shortcode: render failed: ' . $e::class . ': ' . $e->getMessage());

            return '<section class="cat-detail cat-detail--method alignwide" id="' . $id . '" aria-labelledby="' . $titleId . '"><h2 class="cat-detail__title" id="' . $titleId . '">Cómo se calcula</h2><p>La metodología no está disponible por el momento.</p></section>';
        }
    }

    /**
     * First instance keeps the stable anchor "metodologia"; later ones get "-2", "-3"… so ids stay unique.
     *
     * @return array{0: string, 1: string} Section id and heading id.
     */
    private static function nextIds(): array
    {
        $n = ++self::$renders;

        return $n === 1 ? ['metodologia', 'metodologia-title'] : ['metodologia-' . $n, 'metodologia-title-' . $n];
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
