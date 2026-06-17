<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\ReportsTable;
use Defyn\Dashboard\Services\ReportsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ReportsRepositoryMarkSentTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::ensureSchema();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_reports');
    }

    private function seedReport(): int
    {
        global $wpdb;
        $wpdb->insert(ReportsTable::tableName(), [
            'site_id'=>1,'title'=>'May 2026','range_from'=>'2026-05-01','range_to'=>'2026-05-31',
            'status'=>'ready','file_name'=>'report-1-abc.pdf','file_size'=>1234,
            'created_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    public function testMarkSentDefaultsToManual(): void
    {
        $repo = new ReportsRepository();
        $id = $this->seedReport();
        $repo->markSent($id, 'c@acme.com', '2026-06-01 03:00:00');
        $r = $repo->findByIdForSite($id, 1);
        self::assertSame('sent', $r->status);
        self::assertSame('manual', $r->sentMethod);
        self::assertSame('manual', $r->toJson()['sent_method']);
    }

    public function testMarkSentAutoMethod(): void
    {
        $repo = new ReportsRepository();
        $id = $this->seedReport();
        $repo->markSent($id, 'c@acme.com', '2026-06-01 03:00:00', 'auto');
        self::assertSame('auto', $repo->findByIdForSite($id, 1)->sentMethod);
    }
}
