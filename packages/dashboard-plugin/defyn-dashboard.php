<?php
/**
 * Plugin Name:       DefynWP Dashboard
 * Plugin URI:        https://defyn.dev
 * Description:       Central dashboard for managing multiple WordPress sites — the backend brain.
 * Version:           0.2.0
 * Requires at least: 5.5
 * Requires PHP:      8.1
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

// JWT secret: required for auth REST endpoints in F3a+. Loaded from environment
// (Bedrock's .env in production; wp-config.php define() in plain WP).
// Plugin still loads if absent — we only fatal at the auth endpoints, with a
// clear admin-notice fallback so the operator can fix the config without losing
// access to wp-admin.
if (!defined('DEFYN_JWT_SECRET')) {
    $envSecret = getenv('DEFYN_JWT_SECRET');
    if ($envSecret !== false && $envSecret !== '') {
        define('DEFYN_JWT_SECRET', $envSecret);
    }
}

// Constants used throughout the plugin
define('DEFYN_DASHBOARD_VERSION', '0.1.0');
define('DEFYN_DASHBOARD_FILE', __FILE__);
define('DEFYN_DASHBOARD_DIR', __DIR__);

// CORS: allow the SPA origin. Override via env (DEFYN_SPA_ORIGIN) for prod.
if (!defined('DEFYN_SPA_ORIGIN')) {
    $envOrigin = getenv('DEFYN_SPA_ORIGIN');
    define('DEFYN_SPA_ORIGIN', ($envOrigin !== false && $envOrigin !== '') ? $envOrigin : 'http://localhost:5173');
}

// Vault key: required for encrypting per-site dashboard private keys (F5+).
// Plugin still loads if absent — only fatal at endpoints that touch the vault,
// with an admin-notice fallback so the operator can fix config without locking
// themselves out of wp-admin.
if (!defined('DEFYN_VAULT_KEY')) {
    $envVaultKey = getenv('DEFYN_VAULT_KEY');
    if ($envVaultKey !== false && $envVaultKey !== '') {
        define('DEFYN_VAULT_KEY', $envVaultKey);
    }
}

// Action Scheduler: loaded before Plugin::boot() so as_schedule_single_action()
// and the hook system are available when controllers / Plugin::boot() reference them.
// Loading is idempotent — if another plugin loaded AS first (its own copy ships
// inside WooCommerce, for example), this require_once is a no-op.
$asBootstrap = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
if (file_exists($asBootstrap)) {
    require_once $asBootstrap;
}

// Boot
\Defyn\Dashboard\Plugin::instance()->boot();
