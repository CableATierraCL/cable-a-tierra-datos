<?php

declare(strict_types=1);

namespace CableATierra\Datos\Wp;

/**
 * A plugin stylesheet. Registered on wp_enqueue_scripts and enqueued up front when the request
 * needs it (the band: the front page; the detail tables: a page whose content has the shortcode);
 * the shortcode enqueues it too. A block theme renders the template before wp_head, so a style
 * enqueued while the shortcode runs still prints in <head>; in a classic theme it lands in the
 * footer, which is why the up-front enqueue exists as well.
 */
final class Assets
{
    public const HANDLE = 'cat-datos-band';
    public const DETAIL_HANDLE = 'cat-datos-detail';
    private const PATH = 'assets/css/band.css';
    private const DETAIL_PATH = 'assets/css/detail.css';

    /** @param ?\Closure $upFront Whether to enqueue on this request; the front page when null. */
    public function __construct(
        private readonly string $dir = CAT_DATOS_DIR,
        private readonly string $handle = self::HANDLE,
        private readonly string $path = self::PATH,
        private readonly ?\Closure $upFront = null,
    ) {
    }

    /** The detail stylesheet (tables and methodology), enqueued up front on a singular page whose content has either shortcode. */
    public static function detail(string $dir = CAT_DATOS_DIR): self
    {
        return new self($dir, self::DETAIL_HANDLE, self::DETAIL_PATH, static function (): bool {
            if (!is_singular()) {
                return false;
            }
            $content = (string) get_post_field('post_content', get_queried_object_id());

            return has_shortcode($content, DetailShortcode::TAG) || has_shortcode($content, MethodologyShortcode::TAG);
        });
    }

    public function register(): void
    {
        $mtime = @filemtime($this->dir . $this->path);

        wp_register_style($this->handle, plugins_url($this->path, CAT_DATOS_FILE), [], $mtime === false ? CAT_DATOS_VERSION : (string) $mtime);
    }

    public function enqueue(): void
    {
        if (!wp_style_is($this->handle, 'registered')) {
            $this->register();
        }
        wp_enqueue_style($this->handle);
    }

    /** wp_enqueue_scripts callback. Never throws into WordPress. */
    public function onEnqueueScripts(): void
    {
        try {
            $this->register();
            if ($this->upFront === null ? is_front_page() : ($this->upFront)()) {
                wp_enqueue_style($this->handle);
            }
        } catch (\Throwable) {
            // The shortcode enqueues the stylesheet itself; an unstyled page beats a broken one.
        }
    }
}
