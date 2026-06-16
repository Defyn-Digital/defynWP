<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * P6.1 — wp_defyn_site_performance.
 *
 * One row per weekly PageSpeed snapshot for a site. Captures the Lighthouse
 * performance score plus the three Core Web Vitals (LCP, CLS, INP) for both
 * the mobile and desktop strategies. All metric columns are NULL-able so a
 * partial fetch (e.g. mobile succeeded, desktop failed) still stores a row.
 * Lookups are by (site_id, fetched_at) for the per-site trend listing.
 */
final class SitePerformanceTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_site_performance';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            mobile_score TINYINT UNSIGNED NULL,
            mobile_lcp_ms INT UNSIGNED NULL,
            mobile_cls DECIMAL(6,3) NULL,
            mobile_inp_ms INT UNSIGNED NULL,
            desktop_score TINYINT UNSIGNED NULL,
            desktop_lcp_ms INT UNSIGNED NULL,
            desktop_cls DECIMAL(6,3) NULL,
            desktop_inp_ms INT UNSIGNED NULL,
            fetched_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_perf_site_fetched (site_id, fetched_at)
        ) {$charset};";
    }
}
