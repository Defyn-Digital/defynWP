<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * P2.9 — wp_defyn_bulk_job_items (spec § 1).
 *
 * Child row — one per scheduled (site_id, resource_slug) pair. State machine:
 * queued → started → succeeded|failed (terminal), queued → cancelled
 * (terminal), failed → queued (operator retry). `resource_slug` works for
 * both plugins and themes (both inventories key on `slug`).
 */
final class BulkJobItemsTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_bulk_job_items';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NOT NULL,
            resource_slug VARCHAR(80) NOT NULL,
            state VARCHAR(20) NOT NULL DEFAULT 'queued',
            error_message VARCHAR(1000) NULL,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_job_state (job_id, state),
            KEY idx_state_completed (state, completed_at)
        ) {$charset};";
    }
}
