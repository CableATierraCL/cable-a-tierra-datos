<?php

declare(strict_types=1);

namespace CableATierra\Datos\Wp;

/**
 * Detects the public /datos/ page. It counts only when it is published and holds
 * [cat_datos_detalle] (attributes allowed), so the band never links to an empty or missing page.
 * One lookup per instance; the plugin shares a single instance per request.
 */
final class DatosPage
{
    public const PATH = 'datos';

    private ?string $url = null;

    /** Permalink of the detail page, or '' when the gate is closed. */
    public function url(): string
    {
        return $this->url ??= $this->lookup();
    }

    private function lookup(): string
    {
        $page = get_page_by_path(self::PATH, 'OBJECT', 'page');
        if (!is_object($page)) {
            return '';
        }
        if (($page->post_status ?? '') !== 'publish' || ($page->post_password ?? '') !== '' || !has_shortcode((string) ($page->post_content ?? ''), DetailShortcode::TAG)) {
            return '';
        }
        $link = get_permalink((int) $page->ID);

        return is_string($link) ? $link : '';
    }
}
