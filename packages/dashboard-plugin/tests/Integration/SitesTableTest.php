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

    public function testActivationIsIdempotent(): void
    {
        global $wpdb;

        // Start from a known clean state.
        $this->freshlyActivate('defyn_sites');
        $firstSchemaVersion = (int) get_option(Activation::SCHEMA_OPTION);

        // Insert a row to make sure activation doesn't drop existing data
        $wpdb->insert(
            $wpdb->prefix . 'defyn_sites',
            [
                'user_id'    => 1,
                'url'        => 'https://example.test',
                'label'      => 'Test',
                'status'     => 'pending',
                'ssl_status' => 'unknown',
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ]
        );
        $rowsBefore = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_sites"
        );

        // Second activation — must not throw, must not lose data
        Activation::activate();
        $secondSchemaVersion = (int) get_option(Activation::SCHEMA_OPTION);

        $rowsAfter = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_sites"
        );

        self::assertSame($firstSchemaVersion, $secondSchemaVersion, 'Schema version should stay the same on repeated activation');
        self::assertSame($rowsBefore, $rowsAfter, 'Existing rows must not be lost on repeated activation');
        self::assertGreaterThan(0, $rowsAfter, 'Sanity: row was inserted');
    }
}
