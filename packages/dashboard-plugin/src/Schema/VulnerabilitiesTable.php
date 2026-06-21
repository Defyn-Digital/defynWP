<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Schema;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables: direct queries are required and results are request-scoped.

/** P4.1 — wp_defyn_vulnerabilities: global cached Wordfence vuln DB (NOT per-site). */
final class VulnerabilitiesTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_vulnerabilities';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id VARCHAR(64) NOT NULL,
            type VARCHAR(10) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            title TEXT NULL,
            severity VARCHAR(10) NOT NULL DEFAULT 'unknown',
            cvss_score DECIMAL(3,1) NULL DEFAULT NULL,
            cve VARCHAR(32) NULL DEFAULT NULL,
            from_version VARCHAR(32) NULL DEFAULT NULL,
            from_inclusive TINYINT NOT NULL DEFAULT 1,
            to_version VARCHAR(32) NULL DEFAULT NULL,
            to_inclusive TINYINT NOT NULL DEFAULT 0,
            fixed_in VARCHAR(32) NULL DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_vuln_range (source_id, type, slug, from_version, to_version),
            KEY idx_vuln_type_slug (type, slug)
        ) {$charset};";
    }
}
