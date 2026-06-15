<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P5.1 — GET /defyn/v1/sites/{id}/report?from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Mirrors SitesVulnerabilitiesTest: JWT auth, ownership gate (404), enveloped
 * body. Covers the default-range, explicit-range, and date-validation branches.
 *
 * @group integration
 */
final class SitesReportTest extends AbstractSchemaTestCase
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
            'url'        => 'https://smartcoding.test',
            'label'      => 'Smart',
            'status'     => 'active',
            'created_at' => '2026-06-15 00:00:00',
            'updated_at' => '2026-06-15 00:00:00',
        ]);
        $this->siteId = (int) $wpdb->insert_id;
    }

    // -------------------------------------------------------------------------
    // 1. 200 + envelope with the default trailing-30-day range
    // -------------------------------------------------------------------------

    public function test200EnvelopeWithDefaultRange(): void
    {
        $response = rest_do_request($this->signed('GET', "/defyn/v1/sites/{$this->siteId}/report"));

        self::assertSame(200, $response->get_status());
        $body = $response->get_data();
        self::assertArrayHasKey('data', $body);
        self::assertNull($body['error']);

        $data = $body['data'];
        self::assertArrayHasKey('period', $data);
        self::assertArrayHasKey('overview', $data);
        self::assertArrayHasKey('updates', $data);
        self::assertArrayHasKey('uptime', $data);
        self::assertArrayHasKey('security', $data);
    }

    // -------------------------------------------------------------------------
    // 2. 200 with an explicit range echoed back in period.from
    // -------------------------------------------------------------------------

    public function testExplicitRange200(): void
    {
        $request = $this->signed('GET', "/defyn/v1/sites/{$this->siteId}/report");
        $request->set_param('from', '2026-05-16');
        $request->set_param('to', '2026-06-15');
        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        $body = $response->get_data();
        self::assertNull($body['error']);
        self::assertSame('2026-05-16', $body['data']['period']['from']);
    }

    // -------------------------------------------------------------------------
    // 3. 400 when from is after to
    // -------------------------------------------------------------------------

    public function testFromAfterToReturns400(): void
    {
        $request = $this->signed('GET', "/defyn/v1/sites/{$this->siteId}/report");
        $request->set_param('from', '2026-06-15');
        $request->set_param('to', '2026-05-01');
        $response = rest_do_request($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('report.invalid_range', $response->get_data()['error']['code']);
    }

    // -------------------------------------------------------------------------
    // 4. 400 when a date is malformed
    // -------------------------------------------------------------------------

    public function testMalformedDateReturns400(): void
    {
        $request = $this->signed('GET', "/defyn/v1/sites/{$this->siteId}/report");
        $request->set_param('from', 'not-a-date');
        $request->set_param('to', '2026-06-15');
        $response = rest_do_request($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('report.invalid_range', $response->get_data()['error']['code']);
    }

    // -------------------------------------------------------------------------
    // 5. 400 when the range exceeds the 366-day maximum span
    // -------------------------------------------------------------------------

    public function testRangeTooLargeReturns400(): void
    {
        $request = $this->signed('GET', "/defyn/v1/sites/{$this->siteId}/report");
        $request->set_param('from', '2020-01-01');
        $request->set_param('to', '2026-01-01');
        $response = rest_do_request($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('report.range_too_large', $response->get_data()['error']['code']);
    }

    // -------------------------------------------------------------------------
    // 6. 404 when the site belongs to a different user
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

        $response = rest_do_request($this->signed('GET', "/defyn/v1/sites/{$otherSiteId}/report"));

        self::assertSame(404, $response->get_status());
        self::assertSame('sites.not_found', $response->get_data()['error']['code']);
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
