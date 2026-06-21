<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SiteAnalyticsTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SiteAnalyticsSchemaTest extends AbstractSchemaTestCase
{
    public function testTableExistsAfterActivation(): void
    {
        Activation::ensureSchema();
        $this->assertTableExists(SiteAnalyticsTable::tableName());
    }

    public function testGa4PropertyColumnExists(): void
    {
        global $wpdb;
        Activation::ensureSchema();
        $table = SitesTable::tableName();
        self::assertNotNull($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'ga4_property_id')));
    }

    public function testSchemaVersionIs16(): void
    {
        self::assertSame(17, Activation::SCHEMA_VERSION);
    }
}
