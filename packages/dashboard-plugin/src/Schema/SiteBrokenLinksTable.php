<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Schema;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables: direct queries are required and results are request-scoped.

final class SiteBrokenLinksTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_site_broken_links';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();
        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            url VARCHAR(2048) NOT NULL,
            url_hash CHAR(40) NOT NULL,
            source_url VARCHAR(2048) NOT NULL,
            source_hash CHAR(40) NOT NULL,
            source_title VARCHAR(255) NULL,
            anchor_text VARCHAR(255) NULL,
            status_code SMALLINT UNSIGNED NULL,
            severity VARCHAR(10) NOT NULL,
            reason VARCHAR(20) NOT NULL,
            link_type VARCHAR(10) NOT NULL,
            first_detected_at DATETIME NOT NULL,
            last_detected_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_links_site_url_src (site_id, url_hash, source_hash),
            KEY idx_links_site (site_id),
            KEY idx_links_site_sev (site_id, severity)
        ) {$charset};";
    }
}
