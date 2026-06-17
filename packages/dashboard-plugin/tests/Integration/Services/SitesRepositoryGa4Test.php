<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesRepositoryGa4Test extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_sites');
    }

    public function testSetAndReadGa4PropertyId(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'defyn_sites', [
            'user_id'=>1,'url'=>'https://a.test','label'=>'A','status'=>'active','wp_version'=>'6.9',
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        $siteId = (int) $wpdb->insert_id;
        $repo = new SitesRepository();

        self::assertNull($repo->findById($siteId)->ga4PropertyId);
        $repo->setGa4PropertyId($siteId, '123456789');
        self::assertSame('123456789', $repo->findById($siteId)->ga4PropertyId);
        self::assertSame('123456789', $repo->findById($siteId)->toJson()['ga4_property_id']);
        $repo->setGa4PropertyId($siteId, null);
        self::assertNull($repo->findById($siteId)->ga4PropertyId);
    }
}
