<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Jobs\SecurityScan;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P4.1 — POST /defyn/v1/sites/{id}/security/scan
 *
 * Mirrors SitesVulnerabilitiesTest: JWT auth, ownership gate (404),
 * AS job scheduling assertion.
 *
 * VulnFeedService::refreshIfStale() is a no-op in tests because
 * DEFYN_WORDFENCE_API_KEY is not defined — no network calls are made.
 *
 * @group integration
 */
final class SecurityScanControllerTest extends AbstractSchemaTestCase
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
    // 1. 202: authenticated owner → job scheduled
    // -------------------------------------------------------------------------

    public function testAuthenticatedOwnerPostReturns202AndSchedulesJob(): void
    {
        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/security/scan"));

        self::assertSame(202, $response->get_status());

        $body = $response->get_data();
        self::assertArrayHasKey('scheduled', $body);
        self::assertTrue($body['scheduled']);
        self::assertSame($this->siteId, $body['site_id']);

        self::assertNotFalse(
            as_next_scheduled_action(SecurityScan::HOOK, [$this->siteId], 'defyn'),
            'A SecurityScan AS action must have been scheduled for the site'
        );
    }

    // -------------------------------------------------------------------------
    // 2. 202: any team member can trigger a security scan
    // -------------------------------------------------------------------------

    public function testAnyTeamMemberCanTriggerSecurityScan(): void
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

        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$otherSiteId}/security/scan"));

        self::assertSame(202, $response->get_status());
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
