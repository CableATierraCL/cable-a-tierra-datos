<?php

declare(strict_types=1);

namespace CableATierra\Datos\Render;

/**
 * Renders plugin templates in a sandbox: they see only `$view` and `$partial`, never `$this`,
 * and output buffers are unwound if a template throws.
 */
final class Templates
{
    private string $dir;

    // Fixed at construction on purpose: there is no setter, so no runtime path can redirect includes.
    public function __construct(string $dir = '')
    {
        $this->dir = $dir !== '' ? $dir : dirname(__DIR__, 2) . '/templates';
    }

    /** @param array<string, mixed> $view */
    public function render(string $name, array $view): string
    {
        $file = $this->dir . '/' . $name . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Missing template ' . $name);
        }
        $partial = fn (string $n, array $v): string => $this->render($n, $v);
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $file, array $view, callable $partial): void {
                require $file;
            })($file, $view, $partial);

            return trim((string) ob_get_clean());
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }
}
