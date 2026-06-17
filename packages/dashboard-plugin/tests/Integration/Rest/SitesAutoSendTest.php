<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesAutoSendTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::ensureSchema();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_sites');
    }

    private function seedSite(): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'defyn_sites', [
            'user_id'=>1,'url'=>'https://acme.test','label'=>'Acme','status'=>'active','wp_version'=>'6.9',
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function req(int $userId, int $siteId, $autoSend): \WP_REST_Request
    {
        $r = new \WP_REST_Request('POST', '/x');
        $r->set_param('_authenticated_user_id', $userId);
        $r->set_param('id', $siteId);
        $r->set_body(json_encode(['auto_send' => $autoSend]));
        $r->set_header('Content-Type', 'application/json');
        return $r;
    }

    public function testNonOwned404(): void
    {
        $res = (new \Defyn\Dashboard\Rest\SitesAutoSendController())->handle($this->req(1, 999999, true));
        self::assertSame(404, $res->get_status());
        self::assertSame('sites.not_found', $res->get_data()['error']['code']);
    }

    public function testToggleOnPersists(): void
    {
        $siteId = $this->seedSite();
        $res = (new \Defyn\Dashboard\Rest\SitesAutoSendController())->handle($this->req(1, $siteId, true));
        self::assertSame(200, $res->get_status());
        self::assertTrue($res->get_data()['data']['auto_send_reports']);
        self::assertTrue((new SitesRepository())->findById($siteId)->autoSendReports);
    }

    public function testToggleOffPersists(): void
    {
        $siteId = $this->seedSite();
        (new SitesRepository())->setAutoSendReports($siteId, true);
        $res = (new \Defyn\Dashboard\Rest\SitesAutoSendController())->handle($this->req(1, $siteId, false));
        self::assertFalse($res->get_data()['data']['auto_send_reports']);
        self::assertFalse((new SitesRepository())->findById($siteId)->autoSendReports);
    }

    public function testNonBooleanDefaultsOff(): void
    {
        $siteId = $this->seedSite();
        (new SitesRepository())->setAutoSendReports($siteId, true);
        $res = (new \Defyn\Dashboard\Rest\SitesAutoSendController())->handle($this->req(1, $siteId, 'yes')); // not strict true
        self::assertFalse($res->get_data()['data']['auto_send_reports']);
    }
}
