<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\IncidentsTable;
use Defyn\Dashboard\Schema\SitesTable;
use WP_UnitTestCase;

final class IncidentsSchemaTest extends WP_UnitTestCase
{
    public function test_ensure_schema_creates_incidents_table(): void
    {
        global $wpdb;
        Activation::ensureSchema();
        $table = IncidentsTable::tableName();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $this->assertSame($table, $found);
    }

    public function test_ensure_schema_adds_consecutive_failures_column(): void
    {
        global $wpdb;
        Activation::ensureSchema();
        $sites = SitesTable::tableName();
        $col   = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$sites}` LIKE %s", 'consecutive_failures'));
        $this->assertSame('consecutive_failures', $col);
    }

    public function test_schema_version_is_8(): void
    {
        $this->assertSame(9, Activation::SCHEMA_VERSION);
    }
}
