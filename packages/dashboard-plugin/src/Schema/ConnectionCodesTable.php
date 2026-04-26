<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * Schema for wp_defyn_connection_codes — see spec § 4.1.
 * Short-lived handshake tokens with 15-minute expiry.
 */
final class ConnectionCodesTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_connection_codes';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            code VARCHAR(32) NOT NULL,
            site_url VARCHAR(255) NOT NULL,
            site_nonce VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (code),
            KEY expires_at (expires_at)
        ) {$charset};";
    }
}
