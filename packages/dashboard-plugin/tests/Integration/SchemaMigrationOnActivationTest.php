<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SchemaVersion;
use Defyn\Dashboard\Schema\SitePluginsTable;

/**
 * @group integration
 */
final class SchemaMigrationOnActivationTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        delete_option(SchemaVersion::OPTION);

        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . SitePluginsTable::tableName());
    }

    public function testActivationCreatesNewTableAndBumpsVersion(): void
    {
        Activation::activate();

        // Note: WP test harness rewrites CREATE TABLE → CREATE TEMPORARY TABLE,
        // so SHOW TABLES won't list it. Use the documented assertTableExists()
        // helper (uses DESCRIBE), same approach as SitePluginsTableTest.
        $this->assertTableExists(SitePluginsTable::tableName());
        self::assertSame(Activation::SCHEMA_VERSION, SchemaVersion::current());
    }

    public function testActivationIsIdempotent(): void
    {
        Activation::activate();
        Activation::activate();
        self::assertSame(Activation::SCHEMA_VERSION, SchemaVersion::current());
    }
}
