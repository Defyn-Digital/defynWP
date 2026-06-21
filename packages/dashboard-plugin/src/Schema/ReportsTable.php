<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables: direct queries are required and results are request-scoped.

/**
 * P5.3 — wp_defyn_reports.
 *
 * One row per stored maintenance-report PDF in a site's report queue. `status`
 * tracks the generation/send lifecycle (generating → generated → sent/failed);
 * file_name/file_size describe the rendered PDF; recipient_email + sent_at
 * record the client delivery. Lookups are by (site_id, created_at) for the
 * per-site queue listing.
 */
final class ReportsTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_reports';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(160) NOT NULL DEFAULT 'Website Maintenance Report',
            range_from DATE NOT NULL,
            range_to DATE NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'generating',
            file_name VARCHAR(255) NULL,
            file_size INT UNSIGNED NULL,
            recipient_email VARCHAR(255) NULL,
            error_message TEXT NULL,
            generated_at DATETIME NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_reports_site_created (site_id, created_at)
        ) {$charset};";
    }
}
