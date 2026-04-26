<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * Schema for wp_defyn_sites — see spec § 4.1.
 *
 * Returns the dbDelta-compatible CREATE TABLE statement.
 * dbDelta requires: PRIMARY KEY on its own line; two spaces after PRIMARY KEY;
 * uppercase keywords; one column per line.
 */
final class SitesTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_sites';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            url VARCHAR(255) NOT NULL,
            label VARCHAR(120) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            site_public_key TEXT NULL,
            our_public_key TEXT NULL,
            our_private_key TEXT NULL,
            wp_version VARCHAR(20) NULL,
            php_version VARCHAR(20) NULL,
            active_theme LONGTEXT NULL,
            plugin_counts LONGTEXT NULL,
            theme_counts LONGTEXT NULL,
            ssl_status VARCHAR(20) NOT NULL DEFAULT 'unknown',
            ssl_expires_at DATETIME NULL,
            last_contact_at DATETIME NULL,
            last_sync_at DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY user_url (user_id, url(191))
        ) {$charset};";
    }
}
