<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Services\SiteAnalyticsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesAnalyticsTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_site_analytics','defyn_sites'] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t);
        }
    }

    private function seedSite(): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'defyn_sites', [
            'user_id'=>1,'url'=>'https://acme.test','label'=>'Acme','status'=>'active','wp_version'=>'6.9.4',
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function req(string $method, int $userId, int $siteId): \WP_REST_Request
    {
        $r = new \WP_REST_Request($method, '/x');
        $r->set_param('_authenticated_user_id', $userId);
        $r->set_param('id', $siteId);
        return $r;
    }

    public function testGetNonOwned404(): void
    {
        $res = (new \Defyn\Dashboard\Rest\SitesAnalyticsController())->handle($this->req('GET', 1, 999999));
        self::assertSame(404, $res->get_status());
        self::assertSame('sites.not_found', $res->get_data()['error']['code']);
    }

    public function testGetLatestNullAndPropertyWhenNone(): void
    {
        $siteId = $this->seedSite();
        $res = (new \Defyn\Dashboard\Rest\SitesAnalyticsController())->handle($this->req('GET', 1, $siteId));
        self::assertSame(200, $res->get_status());
        self::assertNull($res->get_data()['data']['latest']);
        self::assertNull($res->get_data()['data']['ga4_property_id']);
    }

    public function testGetReturnsSnapshotAndProperty(): void
    {
        $siteId = $this->seedSite();
        (new SitesRepository())->setGa4PropertyId($siteId, '123456789');
        (new SiteAnalyticsRepository())->upsertForSiteAndPeriod($siteId, '2026-06-01', '2026-06-30',
            ['sessions'=>12480,'users'=>9210,'pageviews'=>31540,'avg_engagement'=>108.5,'top_pages'=>[],'channels'=>[]],
            '2026-06-30 03:00:00', '2026-06-30 03:00:00');
        $res = (new \Defyn\Dashboard\Rest\SitesAnalyticsController())->handle($this->req('GET', 1, $siteId));
        self::assertSame(12480, $res->get_data()['data']['latest']['sessions']);
        self::assertSame('123456789', $res->get_data()['data']['ga4_property_id']);
    }

    public function testSetPropertyValidatesAndStores(): void
    {
        $siteId = $this->seedSite();
        $req = $this->req('POST', 1, $siteId);
        $req->set_body(json_encode(['ga4_property_id' => '123456789']));
        $req->set_header('Content-Type', 'application/json');
        $res = (new \Defyn\Dashboard\Rest\SitesGa4PropertyController())->handle($req);
        self::assertSame(200, $res->get_status());
        self::assertSame('123456789', $res->get_data()['data']['ga4_property_id']);
        self::assertSame('123456789', (new SitesRepository())->findById($siteId)->ga4PropertyId);
    }

    public function testSetPropertyRejectsNonNumeric(): void
    {
        $siteId = $this->seedSite();
        $req = $this->req('POST', 1, $siteId);
        $req->set_body(json_encode(['ga4_property_id' => 'G-ABC123']));
        $req->set_header('Content-Type', 'application/json');
        $res = (new \Defyn\Dashboard\Rest\SitesGa4PropertyController())->handle($req);
        self::assertSame(400, $res->get_status());
        self::assertSame('analytics.invalid_property_id', $res->get_data()['error']['code']);
    }

    public function testSetPropertyEmptyClears(): void
    {
        $siteId = $this->seedSite();
        (new SitesRepository())->setGa4PropertyId($siteId, '123');
        $req = $this->req('POST', 1, $siteId);
        $req->set_body(json_encode(['ga4_property_id' => '']));
        $req->set_header('Content-Type', 'application/json');
        $res = (new \Defyn\Dashboard\Rest\SitesGa4PropertyController())->handle($req);
        self::assertSame(200, $res->get_status());
        self::assertNull($res->get_data()['data']['ga4_property_id']);
        self::assertNull((new SitesRepository())->findById($siteId)->ga4PropertyId);
    }

    public function testRefreshOwned202(): void
    {
        $siteId = $this->seedSite();
        $res = (new \Defyn\Dashboard\Rest\SitesAnalyticsRefreshController())->handle($this->req('POST', 1, $siteId));
        self::assertSame(202, $res->get_status());
        self::assertTrue($res->get_data()['data']['scheduled']);
    }

    public function testRefreshNonOwned404(): void
    {
        $res = (new \Defyn\Dashboard\Rest\SitesAnalyticsRefreshController())->handle($this->req('POST', 1, 999999));
        self::assertSame(404, $res->get_status());
    }
}
