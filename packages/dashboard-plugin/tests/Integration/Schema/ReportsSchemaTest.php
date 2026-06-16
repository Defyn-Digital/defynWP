<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\ReportsTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ReportsSchemaTest extends AbstractSchemaTestCase
{
    public function testSchemaVersionIs13(): void
    {
        self::assertSame(15, Activation::SCHEMA_VERSION);
    }

    public function testReportsTableExistsWithColumns(): void
    {
        global $wpdb;
        $t = ReportsTable::tableName();
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$t}`"); // phpcs:ignore
        foreach (['id','site_id','title','range_from','range_to','status','file_name','file_size','recipient_email','error_message','generated_at','sent_at','created_at'] as $c) {
            self::assertContains($c, $cols, "missing column {$c}");
        }
    }

    public function testSitesHasClientEmailColumn(): void
    {
        global $wpdb;
        $t = SitesTable::tableName();
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$t}`"); // phpcs:ignore
        self::assertContains('client_email', $cols);
    }
}
