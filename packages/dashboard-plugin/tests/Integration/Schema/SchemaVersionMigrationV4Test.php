<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SchemaVersion;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SchemaVersionMigrationV4Test extends AbstractSchemaTestCase
{
    public function testSchemaVersionConstantIsFour(): void
    {
        // Schema was at v4 when this test was written. SCHEMA_VERSION tracks the
        // current version — update the assertion as the schema evolves.
        $this->assertSame(10, Activation::SCHEMA_VERSION);
    }

    public function testActivationBumpsSchemaVersionToFour(): void
    {
        delete_option(Activation::SCHEMA_OPTION);
        Activation::activate();
        $this->assertGreaterThanOrEqual(4, SchemaVersion::current());
    }

    public function testActiveThemeColumnDroppedAfterMigration(): void
    {
        global $wpdb;
        $sitesTable = SitesTable::tableName();

        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$sitesTable}` LIKE %s",
            'active_theme'
        ));
        if ($exists === null) {
            $wpdb->query("ALTER TABLE `{$sitesTable}` ADD COLUMN active_theme LONGTEXT NULL");
        }

        Activation::ensureSchema();

        $existsAfter = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$sitesTable}` LIKE %s",
            'active_theme'
        ));
        $this->assertNull($existsAfter, 'active_theme column should be dropped after v4 migration');
    }

    public function testDropColumnIsIdempotent(): void
    {
        Activation::ensureSchema();
        Activation::ensureSchema();

        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `" . SitesTable::tableName() . "` LIKE %s",
            'active_theme'
        ));
        $this->assertNull($exists);
    }
}
