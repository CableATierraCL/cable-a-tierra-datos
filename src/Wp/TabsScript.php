<?php

declare(strict_types=1);

namespace CableATierra\Datos\Wp;

/**
 * The region tabs script: deferred, in the footer, only where [cat_datos_detalle] renders. Enqueued
 * by the shortcode itself and, for a singular page whose content has the shortcode, up front on
 * wp_enqueue_scripts. The methodology shortcode never loads it.
 */
final class TabsScript
{
    public const HANDLE = 'cat-datos-tabs';
    private const PATH = 'assets/js/tabs.js';

    public function __construct(private readonly string $dir = CAT_DATOS_DIR)
    {
    }

    public function register(): void
    {
        $mtime = @filemtime($this->dir . self::PATH);

        wp_register_script(
            self::HANDLE,
            plugins_url(self::PATH, CAT_DATOS_FILE),
            [],
            $mtime === false ? CAT_DATOS_VERSION : (string) $mtime,
            ['strategy' => 'defer', 'in_footer' => true],
        );
    }

    public function enqueue(): void
    {
        if (!wp_script_is(self::HANDLE, 'registered')) {
            $this->register();
        }
        wp_enqueue_script(self::HANDLE);
    }

    /** wp_enqueue_scripts callback. Never throws into WordPress. */
    public function onEnqueueScripts(): void
    {
        try {
            $this->register();
            if (is_singular() && has_shortcode((string) get_post_field('post_content', get_queried_object_id()), DetailShortcode::TAG)) {
                wp_enqueue_script(self::HANDLE);
            }
        } catch (\Throwable) {
            // The shortcode enqueues the script itself; tables without tabs beat a broken page.
        }
    }
}
