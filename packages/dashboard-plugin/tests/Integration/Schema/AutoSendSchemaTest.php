<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\ReportsTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class AutoSendSchemaTest extends AbstractSchemaTestCase
{
    public function testAutoSendReportsColumnExists(): void
    {
        global $wpdb;
        Activation::ensureSchema();
        $table = SitesTable::tableName();
        self::assertNotNull($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'auto_send_reports')));
    }

    public function testSentMethodColumnExists(): void
    {
        global $wpdb;
        Activation::ensureSchema();
        $table = ReportsTable::tableName();
        self::assertNotNull($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'sent_method')));
    }

    public function testSchemaVersionIs16(): void
    {
        self::assertSame(16, Activation::SCHEMA_VERSION);
    }
}
