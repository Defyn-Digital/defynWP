<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SitePerformanceTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class PerformanceSchemaTest extends AbstractSchemaTestCase
{
    public function testSchemaVersionIs16(): void
    {
        self::assertSame(16, Activation::SCHEMA_VERSION);
    }

    public function testPerformanceTableExistsWithColumns(): void
    {
        global $wpdb;
        $t = SitePerformanceTable::tableName();
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$t}`"); // phpcs:ignore
        foreach (['id','site_id','mobile_score','mobile_lcp_ms','mobile_cls','mobile_inp_ms','desktop_score','desktop_lcp_ms','desktop_cls','desktop_inp_ms','fetched_at','created_at'] as $c) {
            self::assertContains($c, $cols, "missing column {$c}");
        }
    }
}
