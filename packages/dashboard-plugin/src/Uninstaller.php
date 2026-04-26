<?php

declare(strict_types=1);

namespace Defyn\Dashboard;

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
    }
}
