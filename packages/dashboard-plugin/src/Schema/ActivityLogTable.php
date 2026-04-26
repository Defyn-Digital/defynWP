<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * Schema for wp_defyn_activity_log — see spec § 4.1.
 * Audit trail for every meaningful event.
 *
 * (See SitesTable for dbDelta formatting rules — uppercase keywords,
 * two-space `PRIMARY KEY  (id)`, one column per line, no backticks.)
 */
final class ActivityLogTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_activity_log';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            site_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(64) NOT NULL,
            details LONGTEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY site_id (site_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset};";
    }
}
