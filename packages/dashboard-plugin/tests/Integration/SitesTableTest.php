<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Activation;

/**
 * @group integration
 */
final class SitesTableTest extends AbstractSchemaTestCase
{
    public function testActivationCreatesSitesTable(): void
    {
        global $wpdb;

        // Force a clean slate even if state leaked from a previous PHPUnit invocation.
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}defyn_sites");
        delete_option(Activation::SCHEMA_OPTION);

        Activation::activate();

        $this->assertTableExists($wpdb->prefix . 'defyn_sites');
    }

    public function testSitesTableHasRequiredColumns(): void
    {
        global $wpdb;

        // Symmetric setup: don't depend on prior test ordering.
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}defyn_sites");
        delete_option(Activation::SCHEMA_OPTION);

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
