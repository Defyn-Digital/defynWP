<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\DismissedVulnerabilitiesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class DismissedVulnerabilitiesSchemaTest extends AbstractSchemaTestCase
{
    public function testSchemaVersionIsTwelve(): void
    {
        self::assertSame(15, Activation::SCHEMA_VERSION);
    }

    public function testDismissedTableExistsAfterEnsureSchema(): void
    {
        Activation::ensureSchema();
        global $wpdb;
        $table = DismissedVulnerabilitiesTable::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $found = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        self::assertSame($table, $found, 'dismissed_vulnerabilities table should be created by ensureSchema');
    }

    public function testUniqueFingerprintColumnsPresent(): void
    {
        Activation::ensureSchema();
        global $wpdb;
        $table = DismissedVulnerabilitiesTable::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        foreach (['id', 'site_id', 'type', 'slug', 'source_id', 'dismissed_by', 'dismissed_at'] as $c) {
            self::assertContains($c, $cols, "column {$c} must exist");
        }
    }
}
