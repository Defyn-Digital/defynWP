<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesReportDownloadTest extends AbstractSchemaTestCase
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
        $req = $this->req(1, 999999, 1);
        $res = (new \Defyn\Dashboard\Rest\SitesReportDownloadController())->handle($req);
        self::assertSame(404, $res->get_status());
        self::assertSame('sites.not_found', $res->get_data()['error']['code']);
    }

    public function testUnknownReportReturns404(): void
    {
        $siteId = $this->seedSite();
        $req = $this->req(1, $siteId, 999999);
        $res = (new \Defyn\Dashboard\Rest\SitesReportDownloadController())->handle($req);
        self::assertSame(404, $res->get_status());
        self::assertSame('reports.not_found', $res->get_data()['error']['code']);
    }

    public function testGeneratingReportReturns409NotReady(): void
    {
        $siteId = $this->seedSite();
        $rid = (new \Defyn\Dashboard\Services\ReportsRepository())->create($siteId, 'T', '2026-05-01', '2026-05-31', '2026-06-01 00:00:00');
        $req = $this->req(1, $siteId, $rid);
        $res = (new \Defyn\Dashboard\Rest\SitesReportDownloadController())->handle($req);
        self::assertSame(409, $res->get_status());
        self::assertSame('reports.not_ready', $res->get_data()['error']['code']);
    }

    public function testReadyReportEmitsPdf(): void
    {
        $siteId = $this->seedSite();
        $repo = new \Defyn\Dashboard\Services\ReportsRepository();
        $rid = $repo->create($siteId, 'T', '2026-05-01', '2026-05-31', '2026-06-01 00:00:00');
        $stored = (new \Defyn\Dashboard\Services\ReportStorage())->store($rid, '%PDF-1.7 hello');
        $repo->markReady($rid, $stored['file_name'], $stored['size'], '2026-06-01 02:00:00');

        $controller = new class extends \Defyn\Dashboard\Rest\SitesReportDownloadController {
            public string $emittedPdf = '';
            public string $emittedName = '';
            protected function emit(string $pdf, string $filename): void { $this->emittedPdf = $pdf; $this->emittedName = $filename; }
        };
        $res = $controller->handle($this->req(1, $siteId, $rid));
        self::assertSame(200, $res->get_status());
        self::assertStringStartsWith('%PDF-', $controller->emittedPdf);
        self::assertStringEndsWith('.pdf', $controller->emittedName);
        self::assertStringContainsString('2026-05-01', $controller->emittedName);
        (new \Defyn\Dashboard\Services\ReportStorage())->delete($stored['file_name']);
    }

    private function req(int $uid, int $siteId, int $rid): \WP_REST_Request
    {
        $r = new \WP_REST_Request('GET', '/x');
        $r->set_param('_authenticated_user_id', $uid);
        $r->set_param('id', $siteId);
        $r->set_param('rid', $rid);
        return $r;
    }
}
