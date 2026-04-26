<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

/**
 * @group integration
 */
final class ConnectionCodesTableTest extends AbstractSchemaTestCase
{
    public function testActivationCreatesConnectionCodesTable(): void
    {
        global $wpdb;

        $this->freshlyActivate('defyn_connection_codes');

        $this->assertTableExists($wpdb->prefix . 'defyn_connection_codes');
    }

    public function testConnectionCodesTableHasRequiredColumns(): void
    {
        global $wpdb;

        $this->freshlyActivate('defyn_connection_codes');

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}defyn_connection_codes", 0);

        // Spec § 4.1 — required columns
        $required = ['code', 'site_url', 'site_nonce', 'expires_at', 'consumed_at', 'created_at'];

        foreach ($required as $column) {
            self::assertContains($column, $columns, "Connection codes table missing column: {$column}");
        }
    }
}
