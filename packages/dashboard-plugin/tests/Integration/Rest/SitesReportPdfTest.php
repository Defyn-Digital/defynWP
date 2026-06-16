<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Rest\SitesReportPdfController;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P5.2 — GET /defyn/v1/sites/{id}/report.pdf?from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * The success path header()+echo()+exit()s, which can't be exercised through
 * rest_do_request without killing PHPUnit. So:
 *   - error branches (404 / 400) go through rest_do_request — this ALSO proves the
 *     escaped-dot route resolves (a 404 from the controller, not rest_no_route).
 *   - the success path is driven by calling handle() directly on a SUBCLASS whose
 *     emit() captures the bytes instead of exiting.
 *
 * @group integration
 */
final class SitesReportPdfTest extends AbstractSchemaTestCase
{
    private int $userId;
    private string $token;
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());

        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');

        $this->userId = self::factory()->user->create();
        $this->token  = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($this->userId);

        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $this->userId,
            'url'        => 'https://acme.test',
            'label'      => 'Acme',
            'status'     => 'active',
            'created_at' => '2026-06-15 00:00:00',
            'updated_at' => '2026-06-15 00:00:00',
        ]);
        $this->siteId = (int) $wpdb->insert_id;
    }

    // -------------------------------------------------------------------------
    // 404 — site owned by a different user. Also proves the escaped-dot route
    // RESOLVES to the controller (status 404 sites.not_found, NOT rest_no_route).
    // -------------------------------------------------------------------------

    public function testNonOwnedSiteReturns404(): void
    {
        global $wpdb;
        $otherUser = self::factory()->user->create();
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $otherUser,
            'url'        => 'https://other.test',
            'label'      => 'Other',
            'status'     => 'active',
            'created_at' => '2026-06-15 00:00:00',
            'updated_at' => '2026-06-15 00:00:00',
        ]);
        $otherSiteId = (int) $wpdb->insert_id;

        $response = rest_do_request($this->signed('GET', "/defyn/v1/sites/{$otherSiteId}/report.pdf"));

        self::assertSame(404, $response->get_status());
        self::assertSame('sites.not_found', $response->get_data()['error']['code']);
    }

    // -------------------------------------------------------------------------
    // 400 — from is after to.
    // -------------------------------------------------------------------------

    public function testFromAfterToReturns400(): void
    {
        $request = $this->signed('GET', "/defyn/v1/sites/{$this->siteId}/report.pdf");
        $request->set_param('from', '2026-06-15');
        $request->set_param('to', '2026-05-01');
        $response = rest_do_request($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('report.invalid_range', $response->get_data()['error']['code']);
    }

    // -------------------------------------------------------------------------
    // 400 — range exceeds the 366-day maximum span.
    // -------------------------------------------------------------------------

    public function testRangeTooLargeReturns400(): void
    {
        $request = $this->signed('GET', "/defyn/v1/sites/{$this->siteId}/report.pdf");
        $request->set_param('from', '2020-01-01');
        $request->set_param('to', '2026-01-01');
        $response = rest_do_request($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('report.range_too_large', $response->get_data()['error']['code']);
    }

    // -------------------------------------------------------------------------
    // Success — call handle() directly on a subclass that captures emit() instead
    // of exiting. Asserts the captured PDF bytes + filename (host + resolved dates).
    // -------------------------------------------------------------------------

    public function testSuccessEmitsPdf(): void
    {
        $controller = new class extends SitesReportPdfController {
            public string $capturedPdf      = '';
            public string $capturedFilename = '';

            protected function emit(string $pdf, string $filename): void
            {
                $this->capturedPdf      = $pdf;
                $this->capturedFilename = $filename;
            }
        };

        $request = new WP_REST_Request('GET', "/defyn/v1/sites/{$this->siteId}/report.pdf");
        $request->set_param('_authenticated_user_id', $this->userId);
        $request->set_param('id', $this->siteId);
        $request->set_param('from', '2026-05-16');
        $request->set_param('to', '2026-06-15');

        $response = $controller->handle($request);

        self::assertSame(200, $response->get_status());
        self::assertStringStartsWith('%PDF-', $controller->capturedPdf);
        self::assertSame(
            'maintenance-report-acme.test-2026-05-16-to-2026-06-15.pdf',
            $controller->capturedFilename
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function signed(string $method, string $path): WP_REST_Request
    {
        $request = new WP_REST_Request($method, $path);
        $request->set_header('Authorization', 'Bearer ' . $this->token);
        return $request;
    }
}
