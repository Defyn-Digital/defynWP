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
    protected function tearDown(): void
    {
        // DROP TABLE is non-transactional DDL — wp-phpunit's rollback can't undo it.
        // Re-create the schema so any test that runs alphabetically after this one
        // (today or future) finds the tables intact.
        Activation::activate();
        parent::tearDown();
    }

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

        foreach ($tables as $t) {
            $this->assertTableDoesNotExist($t);
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
