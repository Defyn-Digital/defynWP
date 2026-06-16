<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * P6.2 — wp_defyn_site_analytics.
 *
 * One row per (site, calendar-month) GA4 snapshot: headline totals + the
 * top-pages and channels breakdowns (stored as JSON). All metric columns are
 * NULL-able so a partial/zero-traffic month still stores a row. Lookups are by
 * (site_id, period_start) for the report's month match and the latest-for-site
 * panel headline.
 */
final class SiteAnalyticsTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_site_analytics';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            sessions INT UNSIGNED NULL,
            total_users INT UNSIGNED NULL,
            screen_page_views INT UNSIGNED NULL,
            avg_session_duration DECIMAL(10,2) NULL,
            top_pages LONGTEXT NULL,
            channels LONGTEXT NULL,
            fetched_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_analytics_site_period (site_id, period_start)
        ) {$charset};";
    }
}
