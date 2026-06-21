<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Schema;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables: direct queries are required and results are request-scoped.

/** P4.3b — wp_defyn_dismissed_vulnerabilities: per-site dismissal overlay keyed by the
 *  fingerprint (site_id, type, slug, source_id). The scan snapshot is never modified;
 *  this table is consulted at read time to flag/exclude dismissed findings. */
final class DismissedVulnerabilitiesTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_dismissed_vulnerabilities';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(10) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            source_id VARCHAR(64) NOT NULL,
            dismissed_by BIGINT UNSIGNED NOT NULL,
            dismissed_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_dismissed_fp (site_id, type, slug, source_id),
            KEY idx_dismissed_site (site_id)
        ) {$charset};";
    }
}
