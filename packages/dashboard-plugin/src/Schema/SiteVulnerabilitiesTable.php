<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Schema;

/** P4.1 — wp_defyn_site_vulnerabilities: per-site denormalized findings snapshot. */
final class SiteVulnerabilitiesTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_site_vulnerabilities';
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
            component_name VARCHAR(191) NOT NULL,
            installed_version VARCHAR(32) NOT NULL,
            severity VARCHAR(10) NOT NULL DEFAULT 'unknown',
            cvss_score DECIMAL(3,1) NULL DEFAULT NULL,
            cve VARCHAR(32) NULL DEFAULT NULL,
            fixed_in VARCHAR(32) NULL DEFAULT NULL,
            title TEXT NULL,
            source_id VARCHAR(64) NOT NULL,
            scanned_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_sitevuln_site (site_id)
        ) {$charset};";
    }
}
