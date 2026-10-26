<?php
/**
 * PHPUnit bootstrap: Composer autoloader (dev) plus the plugin's own autoloader.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// The plugin file guards against direct access; emulate WordPress being loaded.
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once dirname(__DIR__) . '/cable-a-tierra-datos.php';
