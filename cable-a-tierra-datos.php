<?php
/**
 * Plugin Name:       Cable a Tierra Datos
 * Description:       Home page data band with NoFires fire-risk summaries for the Nuble, Biobio and La Araucania regions.
 * Version:           0.1.0
 * Requires PHP:      8.2
 * Requires at least: 6.4
 * Author:            Cable a Tierra
 * Text Domain:       cable-a-tierra-datos
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('CAT_DATOS_VERSION', '0.1.0');
define('CAT_DATOS_FILE', __FILE__);
define('CAT_DATOS_DIR', __DIR__ . '/');

// PSR-4 autoloader: CableATierra\Datos\Foo\Bar -> src/Foo/Bar.php (no Composer in production).
spl_autoload_register(static function (string $class): void {
    $prefix = 'CableATierra\\Datos\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = CAT_DATOS_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

require_once CAT_DATOS_DIR . 'includes/functions.php';

// The test bootstrap loads this file without WordPress, where add_action does not exist.
if (function_exists('add_action')) {
    add_action('plugins_loaded', [\CableATierra\Datos\Wp\Plugin::class, 'boot']);
}
