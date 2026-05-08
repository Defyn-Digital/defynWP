<?php
/**
 * Runs when the plugin is fully uninstalled. Removes the wp_options blob
 * holding the keypair so the next install starts clean.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('defyn_connector');
