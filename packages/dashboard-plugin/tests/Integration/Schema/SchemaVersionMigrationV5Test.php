<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SchemaVersion;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SchemaVersionMigrationV5Test extends AbstractSchemaTestCase
{
    public function testSchemaVersionConstantIsFive(): void
    {
        $this->assertSame(5, Activation::SCHEMA_VERSION);
    }

    public function testActivationBumpsSchemaVersionToFive(): void
    {
        delete_option(Activation::SCHEMA_OPTION);
        Activation::activate();
        $this->assertGreaterThanOrEqual(5, SchemaVersion::current());
    }

    public function testActivationAddsAllFiveCoreColumns(): void
    {
        global $wpdb;
        $sitesTable = SitesTable::tableName();

        foreach ([
            'core_update_available',
            'core_update_version',
            'core_update_state',
            'last_core_update_error',
            'last_core_update_attempt_at',
        ] as $col) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SHOW COLUMNS FROM `{$sitesTable}` LIKE %s",
                $col
            ));
            if ($exists !== null) {
                $wpdb->query("ALTER TABLE `{$sitesTable}` DROP COLUMN {$col}");
            }
        }

        Activation::ensureSchema();

        $cols = $wpdb->get_results(
            "SHOW COLUMNS FROM `{$sitesTable}`",
            ARRAY_A,
        );
        $byName = [];
        foreach ($cols as $c) {
            $byName[$c['Field']] = $c;
        }

        $this->assertArrayHasKey('core_update_available', $byName);
        $this->assertSame('NO', $byName['core_update_available']['Null']);
        $this->assertSame('0', $byName['core_update_available']['Default']);

        $this->assertArrayHasKey('core_update_version', $byName);
        $this->assertSame('YES', $byName['core_update_version']['Null']);

        $this->assertArrayHasKey('core_update_state', $byName);
        $this->assertSame('NO', $byName['core_update_state']['Null']);
        $this->assertSame('idle', $byName['core_update_state']['Default']);

        $this->assertArrayHasKey('last_core_update_error', $byName);
        $this->assertSame('YES', $byName['last_core_update_error']['Null']);

        $this->assertArrayHasKey('last_core_update_attempt_at', $byName);
        $this->assertSame('YES', $byName['last_core_update_attempt_at']['Null']);
    }

    public function testActivationIsIdempotent(): void
    {
        Activation::ensureSchema();
        Activation::ensureSchema();

        global $wpdb;
        $sitesTable = SitesTable::tableName();
        $cols = $wpdb->get_results("SHOW COLUMNS FROM `{$sitesTable}`", ARRAY_A);
        $found = 0;
        foreach ($cols as $c) {
            if (str_starts_with($c['Field'], 'core_update_') || str_starts_with($c['Field'], 'last_core_update_')) {
                $found++;
            }
        }
        $this->assertSame(5, $found, 'second ensureSchema call must not duplicate columns');
    }

    public function testIndexAddedAndIdempotent(): void
    {
        global $wpdb;
        $sitesTable = SitesTable::tableName();

        Activation::ensureSchema();

        $hasIndex = $wpdb->get_row($wpdb->prepare(
            "SHOW INDEX FROM `{$sitesTable}` WHERE Key_name = %s",
            'idx_core_update_available'
        ));
        $this->assertNotNull($hasIndex, 'idx_core_update_available index should exist');

        Activation::ensureSchema();

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
            $sitesTable,
            'idx_core_update_available'
        ));
        $this->assertSame(1, $count);
    }
}
