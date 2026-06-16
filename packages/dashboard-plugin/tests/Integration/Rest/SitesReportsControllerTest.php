<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Rest\SitesReportsController;
use Defyn\Dashboard\Services\ReportsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P5.3 Tasks 11+12 — SitesReportsController.
 *
 * Routes are NOT registered yet (Task 17), so these call the controller methods
 * directly with hand-built WP_REST_Request objects (no rest_do_request).
 */
final class SitesReportsControllerTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_reports', 'defyn_sites'] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t);
        }
    }

    /** Insert a minimal site row owned by user 1 and return its id. */
    private function seedSite(int $id = 1): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'id'         => $id,
            'user_id'    => 1,
            'url'        => 'https://acme.test',
            'label'      => 'Acme',
            'status'     => 'active',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        return $id;
    }

    public function testCreateNonOwnedReturns404(): void
    {
        $req = new \WP_REST_Request('POST', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', 999999);
        $res = (new SitesReportsController())->handleCreate($req);
        self::assertSame(404, $res->get_status());
        self::assertSame('sites.not_found', $res->get_data()['error']['code']);
    }

    public function testCreateBadRangeReturns400(): void
    {
        $siteId = $this->seedSite(); // owned by user 1
        $req = new \WP_REST_Request('POST', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', $siteId);
        $req->set_param('from', '2026-06-15');
        $req->set_param('to', '2026-05-01');
        $res = (new SitesReportsController())->handleCreate($req);
        self::assertSame(400, $res->get_status());
        self::assertSame('report.invalid_range', $res->get_data()['error']['code']);
    }

    public function testCreateHappyReturns202GeneratingRow(): void
    {
        $siteId = $this->seedSite();
        $req = new \WP_REST_Request('POST', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', $siteId);
        $res = (new SitesReportsController())->handleCreate($req);
        self::assertSame(202, $res->get_status());
        $report = $res->get_data()['data']['report'];
        self::assertSame('generating', $report['status']);
        self::assertArrayNotHasKey('file_name', $report);
        self::assertSame(1, (new ReportsRepository())->countForSite($siteId));
    }

    public function testListNonOwnedReturns404(): void
    {
        $req = new \WP_REST_Request('GET', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', 999999);
        $res = (new SitesReportsController())->handleList($req);
        self::assertSame(404, $res->get_status());
    }

    public function testListReturnsNewestFirstNoFileName(): void
    {
        $siteId = $this->seedSite();
        $repo = new ReportsRepository();
        $repo->create($siteId, 'A', '2026-04-01', '2026-04-30', '2026-05-01 00:00:00');
        $repo->create($siteId, 'B', '2026-05-01', '2026-05-31', '2026-06-01 00:00:00');
        $req = new \WP_REST_Request('GET', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', $siteId);
        $res = (new SitesReportsController())->handleList($req);
        self::assertSame(200, $res->get_status());
        $data = $res->get_data()['data'];
        self::assertSame(2, $data['total']);
        self::assertCount(2, $data['reports']);
        self::assertArrayNotHasKey('file_name', $data['reports'][0]);
    }
}
