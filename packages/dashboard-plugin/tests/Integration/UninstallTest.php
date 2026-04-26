<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Uninstaller;

/**
 * @group integration
 */
final class UninstallTest extends AbstractSchemaTestCase
{
    public function testUninstallDropsAllTables(): void
    {
        global $wpdb;

        // Make sure tables exist first.
        Activation::activate();

        $tables = [
            $wpdb->prefix . 'defyn_sites',
            $wpdb->prefix . 'defyn_connection_codes',
            $wpdb->prefix . 'defyn_activity_log',
        ];

        foreach ($tables as $t) {
            $this->assertTableExists($t);  // pre-condition
        }

        Uninstaller::uninstall();

        // After uninstall, DESCRIBE returns empty for each table.
        foreach ($tables as $t) {
            // phpcs:ignore WordPress.DB.PreparedSQL — table names cannot be parameterized.
            $columns = $wpdb->get_col("DESCRIBE `{$t}`");
            self::assertEmpty($columns, "Table {$t} should not exist after uninstall");
        }
    }

    public function testUninstallRemovesSchemaVersionOption(): void
    {
        Activation::activate();
        self::assertSame(
            Activation::SCHEMA_VERSION,
            (int) get_option(Activation::SCHEMA_OPTION),
            'Pre-condition: schema version should be set after activate'
        );

        Uninstaller::uninstall();

        self::assertFalse(get_option(Activation::SCHEMA_OPTION), 'Schema version option should be deleted');
    }
}
