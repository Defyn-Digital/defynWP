<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Activation;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class SitesTableTest extends WP_UnitTestCase
{
    public function testActivationCreatesSitesTable(): void
    {
        global $wpdb;

        // wp-phpunit may have already-activated state — drop the table first to ensure clean slate.
        // Note: wp-phpunit rewrites CREATE TABLE → CREATE TEMPORARY TABLE inside tests, so SHOW TABLES
        // won't see the table. We assert existence by querying it via DESCRIBE, which works for
        // temporary tables.
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}defyn_sites");
        delete_option(Activation::SCHEMA_OPTION);

        Activation::activate();

        $tableName = $wpdb->prefix . 'defyn_sites';
        $columns   = $wpdb->get_col("DESCRIBE {$tableName}", 0);

        self::assertNotEmpty($columns, "Table {$tableName} was not created on activation");
        self::assertContains('id', $columns, "Table {$tableName} created but missing primary key column");
    }

    public function testSitesTableHasRequiredColumns(): void
    {
        global $wpdb;

        Activation::activate();

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}defyn_sites", 0);

        // Spec § 4.1 — required columns
        $required = [
            'id', 'user_id', 'url', 'label', 'status',
            'site_public_key', 'our_public_key', 'our_private_key',
            'wp_version', 'php_version', 'active_theme',
            'plugin_counts', 'theme_counts',
            'ssl_status', 'ssl_expires_at',
            'last_contact_at', 'last_sync_at', 'last_error',
            'created_at', 'updated_at',
        ];

        foreach ($required as $column) {
            self::assertContains($column, $columns, "Sites table missing column: {$column}");
        }
    }
}
