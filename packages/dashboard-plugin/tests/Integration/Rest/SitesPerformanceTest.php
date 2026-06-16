<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\SitePerformanceRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P6.1 Task 12 — functional coverage for the two performance controllers:
 *   - POST /defyn/v1/sites/{id}/performance/scan (enqueue PSI measure, 202)
 *   - GET  /defyn/v1/sites/{id}/performance (latest snapshot)
 * Both ownership-404 first. Seeds a user_id=1 site and drives the controllers
 * directly (the route-resolution + permission_callback path is covered in
 * PerformanceCorsTest).
 *
 * @group integration
 */
final class SitesPerformanceTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_site_performance', 'defyn_sites'] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t);
        }
    }

    /** Insert a minimal user_id=1 site row and return its id. */
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

    public function testScanNonOwnedReturns404(): void
    {
        $req = new \WP_REST_Request('POST', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', 999999);
        $res = (new \Defyn\Dashboard\Rest\SitesPerformanceScanController())->handle($req);
        self::assertSame(404, $res->get_status());
        self::assertSame('sites.not_found', $res->get_data()['error']['code']);
    }

    public function testScanOwnedReturns202(): void
    {
        $siteId = $this->seedSite();
        $req = new \WP_REST_Request('POST', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', $siteId);
        $res = (new \Defyn\Dashboard\Rest\SitesPerformanceScanController())->handle($req);
        self::assertSame(202, $res->get_status());
        self::assertTrue($res->get_data()['data']['scheduled']);
    }

    public function testGetLatestNullWhenNone(): void
    {
        $siteId = $this->seedSite();
        $req = new \WP_REST_Request('GET', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', $siteId);
        $res = (new \Defyn\Dashboard\Rest\SitesPerformanceController())->handle($req);
        self::assertSame(200, $res->get_status());
        self::assertNull($res->get_data()['data']['latest']);
    }

    public function testGetLatestReturnsSnapshot(): void
    {
        $siteId = $this->seedSite();
        (new SitePerformanceRepository())->store(
            $siteId,
            ['score' => 82, 'lcp_ms' => 2100, 'cls' => 0.14, 'inp_ms' => 180],
            ['score' => 96, 'lcp_ms' => 900, 'cls' => 0.01, 'inp_ms' => 60],
            '2026-06-14 03:00:00',
            '2026-06-14 03:00:05'
        );
        $req = new \WP_REST_Request('GET', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', $siteId);
        $res = (new \Defyn\Dashboard\Rest\SitesPerformanceController())->handle($req);
        self::assertSame(82, $res->get_data()['data']['latest']['mobile_score']);
    }
}
