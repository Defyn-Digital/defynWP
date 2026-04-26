<?php
/**
 * Triggered by WordPress when the plugin is uninstalled (deleted via Plugins → Delete).
 * Loaded with WPINC defined but no plugin code — we have to load Composer + our class manually.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    return; // Composer wasn't installed — nothing we can clean up safely.
}
require_once $autoload;

\Defyn\Dashboard\Uninstaller::uninstall();
