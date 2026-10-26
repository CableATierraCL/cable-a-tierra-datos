<?php
/**
 * Runs when the plugin is deleted from the WordPress admin. Removes cached data, last-good
 * payloads and the cron hook. Deactivating the plugin does not run this file.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN') || !defined('ABSPATH')) {
    exit;
}

if (!defined('CAT_DATOS_DIR')) {
    define('CAT_DATOS_DIR', __DIR__ . '/');
}

require_once CAT_DATOS_DIR . 'includes/autoload.php';

\CableATierra\Datos\Wp\Uninstaller::run();
