<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

/**
 * @group integration
 */
final class SitesTableTest extends AbstractSchemaTestCase
{
    public function testActivationCreatesSitesTable(): void
    {
        global $wpdb;

        $this->freshlyActivate('defyn_sites');

        $this->assertTableExists($wpdb->prefix . 'defyn_sites');
    }

    public function testSitesTableHasRequiredColumns(): void
    {
        global $wpdb;

        $this->freshlyActivate('defyn_sites');

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
