<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P5.3 Task 15 — DELETE /defyn/v1/sites/{id}/reports/{rid}.
 *
 * Tests call SitesReportDeleteController::handle() directly (routes are
 * registered in Task 17). Mirrors the setUp + seedSite from
 * ReportsRepositoryTest.
 *
 * @group integration
 */
final class SitesReportDeleteTest extends AbstractSchemaTestCase
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

    /** Insert a minimal site row and return its id. Columns mirror the real defyn_sites schema. */
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

    public function testNonOwnedReturns404(): void
    {
        $res = (new \Defyn\Dashboard\Rest\SitesReportDeleteController())->handle($this->req(1, 999999, 1));
        self::assertSame(404, $res->get_status());
        self::assertSame('sites.not_found', $res->get_data()['error']['code']);
    }
    public function testUnknownReportReturns404(): void
    {
        $siteId = $this->seedSite();
        $res = (new \Defyn\Dashboard\Rest\SitesReportDeleteController())->handle($this->req(1, $siteId, 999999));
        self::assertSame(404, $res->get_status());
        self::assertSame('reports.not_found', $res->get_data()['error']['code']);
    }
    public function testDeleteRemovesFileAndRowAndLogs(): void
    {
        $siteId = $this->seedSite();
        $repo = new \Defyn\Dashboard\Services\ReportsRepository();
        $rid = $repo->create($siteId, 'T', '2026-05-01', '2026-05-31', '2026-06-01 00:00:00');
        $stored = (new \Defyn\Dashboard\Services\ReportStorage())->store($rid, '%PDF-1.7 x');
        $repo->markReady($rid, $stored['file_name'], $stored['size'], '2026-06-01 02:00:00');
        $res = (new \Defyn\Dashboard\Rest\SitesReportDeleteController())->handle($this->req(1, $siteId, $rid));
        self::assertSame(200, $res->get_status());
        self::assertTrue($res->get_data()['data']['deleted']);
        self::assertNull($repo->findByIdForSite($rid, $siteId));
        self::assertNull((new \Defyn\Dashboard\Services\ReportStorage())->read($stored['file_name']));
        global $wpdb;
        self::assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = 'report.deleted'"));
    }
    private function req(int $uid, int $siteId, int $rid): \WP_REST_Request
    {
        $r = new \WP_REST_Request('DELETE', '/x');
        $r->set_param('_authenticated_user_id', $uid); $r->set_param('id', $siteId); $r->set_param('rid', $rid);
        return $r;
    }
}
