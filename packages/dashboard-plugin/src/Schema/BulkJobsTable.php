<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * P2.9 — wp_defyn_bulk_jobs (spec § 1).
 *
 * Parent row — one per destructive bulk request (P2.7 plugins + P2.8 themes).
 * `kind` is 'plugin_update' | 'theme_update'. started_at/completed_at are
 * maintained automatically by BulkJobsRepository::refreshJobTimestamps.
 *
 * NOTE (plan-bug trap #29): no DESC in the index definition — dbDelta's
 * parser doesn't support it; list ordering is enforced via ORDER BY.
 */
final class BulkJobsTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_bulk_jobs';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(20) NOT NULL,
            scheduled_count INT UNSIGNED NOT NULL DEFAULT 0,
            skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_user_created (user_id, created_at)
        ) {$charset};";
    }
}
