<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * P2.1 — wp_defyn_site_plugins (spec § 5.1).
 */
final class SitePluginsTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_site_plugins';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            slug VARCHAR(191) NOT NULL,
            name VARCHAR(191) NOT NULL,
            version VARCHAR(40) NULL,
            update_available TINYINT(1) NOT NULL DEFAULT 0,
            update_version VARCHAR(40) NULL,
            update_state ENUM('idle','queued','updating','failed') NOT NULL DEFAULT 'idle',
            last_update_error TEXT NULL,
            last_update_attempt_at DATETIME NULL,
            last_seen_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY site_slug (site_id, slug),
            KEY update_available (update_available),
            KEY update_state (update_state),
            KEY site_id (site_id)
        ) {$charset};";
    }
}
