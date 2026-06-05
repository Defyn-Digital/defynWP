<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SchemaVersion;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * @group integration
 *
 * P2.2 Task 7 — verifies schema v3 migration adds the three update-tracking
 * columns + KEY update_state to wp_defyn_site_plugins and bumps SCHEMA_VERSION.
 */
final class SchemaV3MigrationTest extends AbstractSchemaTestCase
{
    public function testFreshActivationCreatesNewColumns(): void
    {
        $this->freshlyActivate('defyn_site_plugins');

        $columns = $this->describeTable(SitePluginsTable::tableName());

        $this->assertArrayHasKey('update_state', $columns);
        $this->assertSame(
            "enum('idle','queued','updating','failed')",
            strtolower($columns['update_state']['Type'])
        );
        $this->assertSame('NO', $columns['update_state']['Null']);
        $this->assertSame('idle', $columns['update_state']['Default']);

        $this->assertArrayHasKey('last_update_error', $columns);
        $this->assertSame('text', strtolower($columns['last_update_error']['Type']));
        $this->assertSame('YES', $columns['last_update_error']['Null']);

        $this->assertArrayHasKey('last_update_attempt_at', $columns);
        $this->assertSame('datetime', strtolower($columns['last_update_attempt_at']['Type']));
        $this->assertSame('YES', $columns['last_update_attempt_at']['Null']);
    }

    public function testUpdateStateIndexExists(): void
    {
        $this->freshlyActivate('defyn_site_plugins');
        $this->assertHasIndex(SitePluginsTable::tableName(), 'update_state');
    }

    public function testSchemaVersionIsAtLeastThree(): void
    {
        Activation::activate();
        $this->assertGreaterThanOrEqual(3, SchemaVersion::current());
    }

    public function testSchemaVersionConstantIsThree(): void
    {
        $this->assertSame(3, Activation::SCHEMA_VERSION);
    }
}
