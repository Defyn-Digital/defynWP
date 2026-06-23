<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Schema\SiteVulnerabilitiesTable;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P4.1 — GET /defyn/v1/sites/{id}/vulnerabilities
 *
 * Mirrors SitesIncidentsTest: JWT auth, ownership gate (404), findings array.
 *
 * Note: SiteVulnerabilitiesRepository::replaceForSite() issues an explicit
 * START TRANSACTION/COMMIT which escapes WP_UnitTestCase's per-test rollback.
 * setUp() freshlyActivates and purges both tables explicitly (guardrail #15).
 *
 * @group integration
 */
final class SitesVulnerabilitiesTest extends AbstractSchemaTestCase
{
    private int $userId;
    private string $token;
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_site_vulnerabilities');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('DELETE FROM ' . SiteVulnerabilitiesTable::tableName());

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
    // 1. 200 + envelope with seeded finding present
    // -------------------------------------------------------------------------

    public function testReturnsVulnerabilitiesForOwnedSite(): void
    {
        $now = '2026-06-15 10:00:00';
        (new SiteVulnerabilitiesRepository())->replaceForSite($this->siteId, [
            [
                'type'              => 'plugin',
                'slug'              => 'contact-form-7',
                'component_name'    => 'Contact Form 7',
                'installed_version' => '5.7.1',
                'severity'          => 'high',
                'cvss_score'        => 7.5,
                'cve'               => 'CVE-2024-1234',
                'fixed_in'          => '5.7.2',
                'title'             => 'XSS in form widget',
                'source_id'         => 'wpscan-abc123',
            ],
        ], $now);

        $response = rest_do_request($this->signed('GET', "/defyn/v1/sites/{$this->siteId}/vulnerabilities"));

        self::assertSame(200, $response->get_status());
        $body = $response->get_data();
        self::assertArrayHasKey('data', $body);
        self::assertNull($body['error']);
        self::assertArrayHasKey('vulnerabilities', $body['data']);
        self::assertArrayHasKey('scanned_at', $body['data']);
        self::assertCount(1, $body['data']['vulnerabilities']);

        $vuln = $body['data']['vulnerabilities'][0];
        self::assertSame('plugin', $vuln['type']);
        self::assertSame('contact-form-7', $vuln['slug']);
        self::assertSame('high', $vuln['severity']);
        self::assertSame('CVE-2024-1234', $vuln['cve']);
    }

    // -------------------------------------------------------------------------
    // 2. 200 with empty vulnerabilities and null scanned_at when nothing seeded
    // -------------------------------------------------------------------------

    public function testReturnsEmptyVulnerabilitiesWhenNoneSeeded(): void
    {
        $response = rest_do_request($this->signed('GET', "/defyn/v1/sites/{$this->siteId}/vulnerabilities"));

        self::assertSame(200, $response->get_status());
        $body = $response->get_data();
        self::assertNull($body['error']);
        self::assertSame([], $body['data']['vulnerabilities']);
        self::assertNull($body['data']['scanned_at']);
    }

    // -------------------------------------------------------------------------
    // 3. 200 when site belongs to a different team member
    // -------------------------------------------------------------------------

    public function testAnyTeamMemberCanViewSiteVulnerabilities(): void
    {
        // Team-wide: per-user filter removed (2026-06-22 SSO spec).
        global $wpdb;
        $otherUserId = self::factory()->user->create();
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $otherUserId,
            'url'        => 'https://other.test',
            'label'      => 'Other',
            'status'     => 'active',
            'created_at' => '2026-06-15 00:00:00',
            'updated_at' => '2026-06-15 00:00:00',
        ]);
        $otherSiteId = (int) $wpdb->insert_id;

        $response = rest_do_request($this->signed('GET', "/defyn/v1/sites/{$otherSiteId}/vulnerabilities"));
        self::assertSame(200, $response->get_status());
    }

    // -------------------------------------------------------------------------
    // 4. 401 when unauthenticated
    // -------------------------------------------------------------------------

    public function testUnauthenticatedReturns401(): void
    {
        $request  = new WP_REST_Request('GET', "/defyn/v1/sites/{$this->siteId}/vulnerabilities");
        $response = rest_do_request($request);
        self::assertSame(401, $response->get_status());
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
