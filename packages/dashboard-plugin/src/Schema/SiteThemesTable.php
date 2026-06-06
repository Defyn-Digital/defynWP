<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * P2.3 — wp_defyn_site_themes (spec § 2.1).
 *
 * Mirrors SitePluginsTable plus two theme-specific columns: parent_slug
 * (NULL for standalone themes; populated for child themes) and is_active
 * (exactly one row per site is true at any time; enforced transactionally
 * by SyncThemesService rather than a DB constraint).
 */
final class SiteThemesTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_site_themes';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            slug VARCHAR(80) NOT NULL,
            name VARCHAR(255) NOT NULL,
            version VARCHAR(50) NULL,
            parent_slug VARCHAR(80) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            update_available TINYINT(1) NOT NULL DEFAULT 0,
            update_version VARCHAR(50) NULL,
            update_state VARCHAR(20) NOT NULL DEFAULT 'idle',
            last_update_error VARCHAR(1000) NULL,
            last_update_attempt_at DATETIME NULL,
            last_seen_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_site_slug (site_id, slug),
            KEY idx_site_id (site_id),
            KEY idx_update_available (site_id, update_available)
        ) {$charset};";
    }
}
