<?php

declare(strict_types=1);

namespace CableATierra\Datos\Wp;

/**
 * The band stylesheet. Registered on wp_enqueue_scripts and enqueued on the front page; the
 * shortcode enqueues it too. A block theme renders the template before wp_head, so a style
 * enqueued while the shortcode runs still prints in <head>; in a classic theme it lands in the
 * footer, which is why the front page enqueues it up front as well.
 */
final class Assets
{
    public const HANDLE = 'cat-datos-band';
    private const PATH = 'assets/css/band.css';

    public function __construct(private readonly string $dir = CAT_DATOS_DIR)
    {
    }

    public function register(): void
    {
        $mtime = @filemtime($this->dir . self::PATH);

        wp_register_style(self::HANDLE, plugins_url(self::PATH, CAT_DATOS_FILE), [], $mtime === false ? CAT_DATOS_VERSION : (string) $mtime);
    }

    public function enqueue(): void
    {
        if (!wp_style_is(self::HANDLE, 'registered')) {
            $this->register();
        }
        wp_enqueue_style(self::HANDLE);
    }

    /** wp_enqueue_scripts callback. Never throws into WordPress. */
    public function onEnqueueScripts(): void
    {
        try {
            $this->register();
            if (is_front_page()) {
                wp_enqueue_style(self::HANDLE);
            }
        } catch (\Throwable) {
            // The shortcode enqueues the stylesheet itself; an unstyled band beats a broken page.
        }
    }
}
