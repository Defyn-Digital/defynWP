<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P3.2 — schema v9: last_response_time_ms on wp_defyn_sites (guarded ALTER).
 *
 * @group integration
 */
final class ResponseTimeColumnTest extends AbstractSchemaTestCase
{
    public function testSchemaVersionConstantIsNine(): void
    {
        self::assertSame(9, Activation::SCHEMA_VERSION);
    }

    public function testResponseTimeColumnExistsAfterEnsureSchema(): void
    {
        $this->freshlyActivate('defyn_sites');
        Activation::ensureSchema();

        global $wpdb;
        $table = SitesTable::tableName();
        $col = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'last_response_time_ms'
        ));
        self::assertSame('last_response_time_ms', $col);
    }

    public function testGuardedAlterIsIdempotent(): void
    {
        $this->freshlyActivate('defyn_sites');
        Activation::ensureSchema();
        Activation::ensureSchema(); // second run must not error or duplicate

        global $wpdb;
        $table = SitesTable::tableName();
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table,
            'last_response_time_ms'
        ));
        self::assertSame('1', (string) $count);
    }
}
