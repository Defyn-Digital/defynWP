<?php
/**
 * Plugin Name:       DefynWP Connector
 * Plugin URI:        https://defyn.dev
 * Description:       DefynWP — connector agent for managed WordPress sites. Pairs with the central DefynWP Dashboard.
 * Version:           0.2.4
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            DefynWP
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       defyn-connector
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$defyn_connector_autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($defyn_connector_autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>DefynWP Connector:</strong> Composer dependencies missing. ';
        echo 'Run <code>composer install</code> in the plugin directory.';
        echo '</p></div>';
    });
    return;
}
require_once $defyn_connector_autoload;

if (!extension_loaded('sodium')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>DefynWP Connector:</strong> The PHP <code>sodium</code> extension is required.';
        echo '</p></div>';
    });
    return;
}

define('DEFYN_CONNECTOR_VERSION', '0.2.4');
define('DEFYN_CONNECTOR_FILE', __FILE__);
define('DEFYN_CONNECTOR_DIR', __DIR__);

\Defyn\Connector\Plugin::instance()->boot();
