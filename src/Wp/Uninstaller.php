<?php

declare(strict_types=1);

namespace CableATierra\Datos\Wp;

use CableATierra\Datos\Data\Repository;

/**
 * Removes everything the plugin stored. Touches only keys that start with the plugin prefix:
 * the known keys first (this also reaches an external object cache), then a prefix sweep of the
 * options table for strays such as keys left by an older schema.
 */
final class Uninstaller
{
    private const PREFIX = 'cat_datos_v1_';

    public static function run(): void
    {
        foreach (Repository::transientKeys() as $key) {
            delete_transient($key);
        }
        foreach (Repository::optionKeys() as $key) {
            delete_option($key);
        }
        wp_clear_scheduled_hook(Repository::REFRESH_HOOK);

        self::sweep();
    }

    private static function sweep(): void
    {
        global $wpdb;
        if (!is_object($wpdb)) {
            return;
        }

        // esc_like() stops "_" and "%" from acting as wildcards; the prefix is never user input.
        $plain = $wpdb->esc_like(self::PREFIX) . '%';
        $transient = $wpdb->esc_like('_transient_' . self::PREFIX) . '%';
        $timeout = $wpdb->esc_like('_transient_timeout_' . self::PREFIX) . '%';

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $plain,
            $transient,
            $timeout,
        ));
    }
}
