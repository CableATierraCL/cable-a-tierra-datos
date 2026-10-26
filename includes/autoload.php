<?php
/**
 * PSR-4 autoloader shared by the plugin and its uninstall script (no Composer in production):
 * CableATierra\Datos\Foo\Bar -> src/Foo/Bar.php. Requires CAT_DATOS_DIR.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

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
