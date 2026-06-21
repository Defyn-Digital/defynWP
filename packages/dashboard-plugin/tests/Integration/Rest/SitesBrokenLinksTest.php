<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\BrokenLinksRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P7.1 Task 12 — functional coverage for the two broken-links controllers:
 *   - GET  /defyn/v1/sites/{id}/broken-links (latest scan results)
 *   - POST /defyn/v1/sites/{id}/links/scan (enqueue crawl, 202)
 * Both ownership-404 first. Seeds a user_id=1 site and drives the controllers
 * directly (the route-resolution + permission_callback path is covered in
 * BrokenLinksCorsTest).
 *
 * @group integration
 */
final class SitesBrokenLinksTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_site_broken_links', 'defyn_sites'] as $t) {
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

    // -------------------------------------------------------------------------
    // POST /links/scan
    // -------------------------------------------------------------------------

    public function testScanNonOwnedReturns404(): void
    {
        $req = new \WP_REST_Request('POST', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', 999999);
        $res = (new \Defyn\Dashboard\Rest\SitesLinksScanController())->handle($req);
        self::assertSame(404, $res->get_status());
        self::assertSame('sites.not_found', $res->get_data()['error']['code']);
    }

    public function testScanOwnedReturns202(): void
    {
        $siteId = $this->seedSite();
        $req    = new \WP_REST_Request('POST', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', $siteId);
        $res = (new \Defyn\Dashboard\Rest\SitesLinksScanController())->handle($req);
        self::assertSame(202, $res->get_status());
        self::assertTrue($res->get_data()['data']['scheduled']);
    }

    public function testScanEnqueuesLinkScanAction(): void
    {
        $siteId = $this->seedSite();
        $req    = new \WP_REST_Request('POST', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', $siteId);
        (new \Defyn\Dashboard\Rest\SitesLinksScanController())->handle($req);

        $next = as_next_scheduled_action(\Defyn\Dashboard\Jobs\LinkScan::HOOK, [$siteId], 'defyn');
        self::assertNotFalse($next, 'LinkScan action should be enqueued in group defyn');
    }

    // -------------------------------------------------------------------------
    // GET /broken-links
    // -------------------------------------------------------------------------

    public function testGetNonOwnedReturns404(): void
    {
        $req = new \WP_REST_Request('GET', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', 999999);
        $res = (new \Defyn\Dashboard\Rest\SitesBrokenLinksController())->handle($req);
        self::assertSame(404, $res->get_status());
        self::assertSame('sites.not_found', $res->get_data()['error']['code']);
    }

    public function testGetOwnedSiteWithNoLinksReturnsEmptyData(): void
    {
        $siteId = $this->seedSite();
        $req    = new \WP_REST_Request('GET', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', $siteId);
        $res = (new \Defyn\Dashboard\Rest\SitesBrokenLinksController())->handle($req);

        self::assertSame(200, $res->get_status());
        $data = $res->get_data()['data'];
        self::assertNull($data['last_link_scan_at'], 'last_link_scan_at must be null when never scanned');
        self::assertSame(0, $data['counts']['broken']);
        self::assertSame(0, $data['counts']['total']);
        self::assertSame([], $data['links']);
    }

    public function testGetOwnedSiteWithSeededLinksReturnsCounts(): void
    {
        $siteId = $this->seedSite();
        $scanAt = '2026-06-21 10:00:00';
        $repo   = new BrokenLinksRepository();

        $repo->upsertForSite($siteId, [
            'url'         => 'https://acme.test/broken-page',
            'source_url'  => 'https://acme.test/',
            'severity'    => 'broken',
            'reason'      => 'http_404',
            'link_type'   => 'internal',
            'status_code' => 404,
        ], $scanAt);

        $repo->upsertForSite($siteId, [
            'url'         => 'https://example.com/redirect',
            'source_url'  => 'https://acme.test/about',
            'severity'    => 'warning',
            'reason'      => 'http_301',
            'link_type'   => 'external',
            'status_code' => 301,
        ], $scanAt);

        $req = new \WP_REST_Request('GET', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', $siteId);
        $res = (new \Defyn\Dashboard\Rest\SitesBrokenLinksController())->handle($req);

        self::assertSame(200, $res->get_status());
        $data = $res->get_data()['data'];
        self::assertSame(1, $data['counts']['broken']);
        self::assertSame(1, $data['counts']['warning']);
        self::assertSame(2, $data['counts']['total']);
        self::assertCount(2, $data['links']);
    }
}
