<?php

declare(strict_types=1);

namespace Defyn\Dashboard;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables: direct queries are required and results are request-scoped.

/**
 * Removes all DefynWP Dashboard data when the plugin is uninstalled (deleted).
 * Triggered by WP via uninstall.php in the plugin root.
 *
 * Iterates Activation::TABLES so the uninstall list never drifts from the
 * activation list — they're the same source of truth.
 */
final class Uninstaller
{
    public static function uninstall(): void
    {
        global $wpdb;

        foreach (Activation::TABLES as $table) {
            $name = $table::tableName();
            // phpcs:ignore WordPress.DB.PreparedSQL — table names cannot be parameterized.
            $wpdb->query("DROP TABLE IF EXISTS `{$name}`");
        }

        delete_option(Activation::SCHEMA_OPTION);

        // P3.3 — clear every operator's Slack webhook (delete_metadata bulk form).
        delete_metadata('user', 0, 'defyn_slack_webhook_url', '', true);

        // P5.3 — remove stored report PDFs + their guard files.
        $up  = wp_upload_dir();
        $dir = rtrim($up['basedir'], '/') . '/defyn-reports';
        if (is_dir($dir)) {
            $entries = array_merge(
                (array) glob($dir . '/*'),
                [$dir . '/.htaccess'] // dotfile — glob('/*') skips it
            );
            foreach ($entries as $f) {
                if (is_file($f)) {
                    @unlink($f); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing plugin-private files on uninstall
                }
            }
            @rmdir($dir); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing plugin-private directory on uninstall
        }
    }
}
