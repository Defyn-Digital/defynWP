<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SchemaVersion;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Schema\VulnerabilitiesTable;
use Defyn\Dashboard\Schema\SiteVulnerabilitiesTable;

final class SecurityScanningSchemaTest extends AbstractSchemaTestCase
{
    public function testActivationCreatesVulnTablesAndBumpsToEleven(): void
    {
        Activation::activate();
        $this->assertTableExists(VulnerabilitiesTable::tableName());
        $this->assertTableExists(SiteVulnerabilitiesTable::tableName());
        self::assertSame(12, Activation::SCHEMA_VERSION);
        self::assertSame(Activation::SCHEMA_VERSION, SchemaVersion::current());
    }

    public function testSitesHasLastSecurityScanAtColumn(): void
    {
        global $wpdb;
        Activation::activate();
        $table = SitesTable::tableName();
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'last_security_scan_at'));
        self::assertSame('last_security_scan_at', $col);
    }

    public function testGuardedAlterIsIdempotent(): void
    {
        Activation::ensureSchema();
        Activation::ensureSchema();
        self::assertSame(Activation::SCHEMA_VERSION, SchemaVersion::current());
    }
}
