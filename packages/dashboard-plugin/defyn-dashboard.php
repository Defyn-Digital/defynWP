<?php
/**
 * Plugin Name:       DefynWP Dashboard
 * Plugin URI:        https://defyn.dev
 * Description:       Central dashboard for managing multiple WordPress sites — the backend brain.
 * Version:           0.1.0
 * Requires at least: 5.5
 * Requires PHP:      7.4
 * Author:            DefynWP
 * License:           Proprietary
 * Text Domain:       defyn-dashboard
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Composer autoloader (vendor is sibling of this file)
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>DefynWP Dashboard:</strong> Composer dependencies missing. ';
        echo 'Run <code>composer install</code> in the plugin directory.';
        echo '</p></div>';
    });
    return;
}
require_once $autoload;

// Constants used throughout the plugin
define('DEFYN_DASHBOARD_VERSION', '0.1.0');
define('DEFYN_DASHBOARD_FILE', __FILE__);
define('DEFYN_DASHBOARD_DIR', __DIR__);

// Boot
\Defyn\Dashboard\Plugin::instance()->boot();
