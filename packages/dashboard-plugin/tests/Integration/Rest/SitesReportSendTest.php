<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P5.3 Task 14 — POST /defyn/v1/sites/{id}/reports/{rid}/send.
 *
 * Routes are NOT registered yet (Task 17), so these tests call handle()
 * directly on a freshly built WP_REST_Request rather than going through
 * rest_do_request. The controller takes an injected ReportMailer so the
 * happy/failure cases can force wp_mail success/failure without sending mail.
 *
 * @group integration
 */
final class SitesReportSendTest extends AbstractSchemaTestCase
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

    public function testNonOwnedReturns404(): void
    {
        $res = (new \Defyn\Dashboard\Rest\SitesReportSendController())->handle($this->req(1, 999999, 1, ['recipient_email' => 'c@acme.test']));
        self::assertSame(404, $res->get_status());
        self::assertSame('sites.not_found', $res->get_data()['error']['code']);
    }

    public function testGeneratingReturns400NotSendable(): void
    {
        $siteId = $this->seedSite();
        $rid = (new \Defyn\Dashboard\Services\ReportsRepository())->create($siteId, 'T', '2026-05-01', '2026-05-31', '2026-06-01 00:00:00');
        $res = (new \Defyn\Dashboard\Rest\SitesReportSendController())->handle($this->req(1, $siteId, $rid, ['recipient_email' => 'c@acme.test']));
        self::assertSame(400, $res->get_status());
        self::assertSame('reports.not_sendable', $res->get_data()['error']['code']);
    }

    public function testBadEmailReturns400(): void
    {
        $siteId = $this->seedSite();
        $rid = $this->seedReadyReport($siteId);
        $res = (new \Defyn\Dashboard\Rest\SitesReportSendController())->handle($this->req(1, $siteId, $rid, ['recipient_email' => 'nope']));
        self::assertSame(400, $res->get_status());
        self::assertSame('reports.invalid_recipient', $res->get_data()['error']['code']);
    }

    public function testHappySendMarksSentAndLogs(): void
    {
        $siteId = $this->seedSite();
        $rid = $this->seedReadyReport($siteId);
        $mailer = new \Defyn\Dashboard\Services\ReportMailer(static fn ($to, $s, $b, $h, $a): bool => true);
        $res = (new \Defyn\Dashboard\Rest\SitesReportSendController($mailer))->handle($this->req(1, $siteId, $rid, ['recipient_email' => 'c@acme.test']));
        self::assertSame(200, $res->get_status());
        self::assertSame('sent', $res->get_data()['data']['report']['status']);
        $repo = new \Defyn\Dashboard\Services\ReportsRepository();
        self::assertSame('sent', $repo->findByIdForSite($rid, $siteId)->status);
        global $wpdb;
        $logged = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = 'report.sent'");
        self::assertSame(1, $logged);
    }

    public function testSendFailureReturns502AndStaysReady(): void
    {
        $siteId = $this->seedSite();
        $rid = $this->seedReadyReport($siteId);
        $mailer = new \Defyn\Dashboard\Services\ReportMailer(static fn ($to, $s, $b, $h, $a): bool => false);
        $res = (new \Defyn\Dashboard\Rest\SitesReportSendController($mailer))->handle($this->req(1, $siteId, $rid, ['recipient_email' => 'c@acme.test']));
        self::assertSame(502, $res->get_status());
        self::assertSame('reports.send_failed', $res->get_data()['error']['code']);
        self::assertSame('ready', (new \Defyn\Dashboard\Services\ReportsRepository())->findByIdForSite($rid, $siteId)->status);
    }

    private function seedReadyReport(int $siteId): int
    {
        $repo = new \Defyn\Dashboard\Services\ReportsRepository();
        $rid = $repo->create($siteId, 'T', '2026-05-01', '2026-05-31', '2026-06-01 00:00:00');
        $stored = (new \Defyn\Dashboard\Services\ReportStorage())->store($rid, '%PDF-1.7 x');
        $repo->markReady($rid, $stored['file_name'], $stored['size'], '2026-06-01 02:00:00');
        return $rid;
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

    private function req(int $uid, int $siteId, int $rid, array $body): WP_REST_Request
    {
        $r = new WP_REST_Request('POST', '/x');
        $r->set_param('_authenticated_user_id', $uid);
        $r->set_param('id', $siteId);
        $r->set_param('rid', $rid);
        $r->set_body(json_encode($body));
        $r->set_header('Content-Type', 'application/json');
        return $r;
    }
}
