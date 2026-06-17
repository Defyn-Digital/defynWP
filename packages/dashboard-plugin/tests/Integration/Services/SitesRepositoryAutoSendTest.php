<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesRepositoryAutoSendTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::ensureSchema();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_sites');
    }

    public function testSetAndReadAutoSendReports(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'defyn_sites', [
            'user_id'=>1,'url'=>'https://a.test','label'=>'A','status'=>'active','wp_version'=>'6.9',
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        $siteId = (int) $wpdb->insert_id;
        $repo = new SitesRepository();

        self::assertFalse($repo->findById($siteId)->autoSendReports); // DEFAULT 0
        $repo->setAutoSendReports($siteId, true);
        self::assertTrue($repo->findById($siteId)->autoSendReports);
        self::assertTrue($repo->findById($siteId)->toJson()['auto_send_reports']);
        $repo->setAutoSendReports($siteId, false);
        self::assertFalse($repo->findById($siteId)->autoSendReports);
    }
}
