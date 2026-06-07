<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Schema\SiteThemesTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SchemaVersionMigrationV6Test extends AbstractSchemaTestCase
{
    public function testSchemaVersionConstantIsSix(): void
    {
        $this->assertSame(6, Activation::SCHEMA_VERSION);
    }

    public function testActivationAddsCoreAllowMajorColumn(): void
    {
        global $wpdb;
        Activation::ensureSchema();

        $table   = SitesTable::tableName();
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);

        $this->assertContains('core_allow_major', $columns);

        $colDef = $wpdb->get_row(
            $wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'core_allow_major'),
            ARRAY_A
        );
        $this->assertStringContainsString('tinyint(1)', strtolower($colDef['Type']));
        $this->assertSame('NO', $colDef['Null']);
        $this->assertSame('0', $colDef['Default']);
    }

    public function testActivationAddsTestedUpToOnPlugins(): void
    {
        global $wpdb;
        Activation::ensureSchema();

        $table   = SitePluginsTable::tableName();
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
        $this->assertContains('tested_up_to', $columns);

        $colDef = $wpdb->get_row(
            $wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'tested_up_to'),
            ARRAY_A
        );
        $this->assertStringContainsString('varchar(20)', strtolower($colDef['Type']));
        $this->assertSame('YES', $colDef['Null']);
    }

    public function testActivationAddsTestedUpToOnThemes(): void
    {
        global $wpdb;
        Activation::ensureSchema();

        $table   = SiteThemesTable::tableName();
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
        $this->assertContains('tested_up_to', $columns);

        $colDef = $wpdb->get_row(
            $wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'tested_up_to'),
            ARRAY_A
        );
        $this->assertStringContainsString('varchar(20)', strtolower($colDef['Type']));
        $this->assertSame('YES', $colDef['Null']);
    }

    public function testV6MigrationIsIdempotent(): void
    {
        Activation::ensureSchema();
        Activation::ensureSchema(); // second call should not error
        $this->assertSame(6, Activation::SCHEMA_VERSION);
    }
}
