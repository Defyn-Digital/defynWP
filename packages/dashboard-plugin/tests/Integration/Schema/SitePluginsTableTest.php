<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * @group integration
 */
final class SitePluginsTableTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_site_plugins');
    }

    public function testTableExists(): void
    {
        // WP_UnitTestCase rewrites CREATE TABLE -> CREATE TEMPORARY TABLE for
        // transaction isolation, and SHOW TABLES does not list temp tables.
        // AbstractSchemaTestCase::assertTableExists uses DESCRIBE which works
        // for both regular and temp tables.
        $this->assertTableExists(SitePluginsTable::tableName());
    }

    public function testUniqueIndexOnSiteIdAndSlug(): void
    {
        global $wpdb;
        $name    = SitePluginsTable::tableName();
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$name}", ARRAY_A);

        $siteSlug = array_filter(
            $indexes,
            static fn(array $i): bool => $i['Key_name'] === 'site_slug'
        );
        self::assertNotEmpty($siteSlug, 'expected UNIQUE KEY site_slug');
        foreach ($siteSlug as $row) {
            self::assertSame('0', (string) $row['Non_unique'], 'site_slug must be UNIQUE');
        }
    }

    public function testUpdateAvailableIndexExists(): void
    {
        global $wpdb;
        $name    = SitePluginsTable::tableName();
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$name}", ARRAY_A);

        $hits = array_filter(
            $indexes,
            static fn(array $i): bool => $i['Key_name'] === 'update_available'
        );
        self::assertNotEmpty($hits, 'expected KEY update_available for fleet queries');
    }
}
