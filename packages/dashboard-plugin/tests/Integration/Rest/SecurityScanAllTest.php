<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Jobs\SecurityScan;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P4.2 — POST /defyn/v1/security/scan-all
 *
 * Mirrors OverviewSyncAllControllerTest: JWT auth, user-scoped fan-out,
 * one fleet activity event only when count > 0, 202 vs 200 response codes,
 * rate-limit enforcement.
 *
 * VulnFeedService::refreshIfStale() is a no-op in tests because
 * DEFYN_WORDFENCE_API_KEY is not defined — no network calls are made.
 *
 * @group integration
 */
final class SecurityScanAllTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_activity_log');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());

        // Clear prior AS rows so per-test assertions on scheduled actions are
        // deterministic (the underlying actionscheduler_actions table is shared
        // across the test runner — TRUNCATE inside a transaction wouldn't roll
        // back AS state on Kinsta's MySQL).
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(SecurityScan::HOOK, null, 'defyn');
        }

        do_action('rest_api_init');
    }

    public function tearDown(): void
    {
        // Wipe the per-user rate-limit transients so tests start fresh.
        for ($i = 1; $i <= 10; $i++) {
            delete_transient("defyn_rl_securityScanAll_{$i}");
        }
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // 1. 401 — no bearer token
    // -------------------------------------------------------------------------

    public function testAuthRequiredReturns401WhenNoBearerToken(): void
    {
        $request  = new WP_REST_Request('POST', '/defyn/v1/security/scan-all');
        $response = rest_do_request($request);

        $this->assertSame(401, $response->get_status());
    }

    // -------------------------------------------------------------------------
    // 2. 202 — happy path: user owns 2 sites → both jobs scheduled
    // -------------------------------------------------------------------------

    public function testHappyPath202WithTwoOwnedSites(): void
    {
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(1);
        $token  = $this->token(1);

        $request = new WP_REST_Request('POST', '/defyn/v1/security/scan-all');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);

        $this->assertSame(202, $response->get_status());
        $body = $response->get_data();
        $this->assertSame(2, $body['scheduled_count']);
        $this->assertEqualsCanonicalizing([$siteA, $siteB], $body['site_ids']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $body['scheduled_at']
        );
    }

    // -------------------------------------------------------------------------
    // 3. SecurityScan job scheduled for each owned site
    // -------------------------------------------------------------------------

    public function testFanOutSchedulesSecurityScanJobPerOwnedSite(): void
    {
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(1);
        $token  = $this->token(1);

        $request = new WP_REST_Request('POST', '/defyn/v1/security/scan-all');
        $request->set_header('Authorization', 'Bearer ' . $token);
        rest_do_request($request);

        $this->assertNotFalse(
            as_next_scheduled_action(SecurityScan::HOOK, [$siteA], 'defyn'),
            "security scan should be scheduled for site A"
        );
        $this->assertNotFalse(
            as_next_scheduled_action(SecurityScan::HOOK, [$siteB], 'defyn'),
            "security scan should be scheduled for site B"
        );
    }

    // -------------------------------------------------------------------------
    // 4. ONE activity event with site_id=null when scheduled_count > 0
    // -------------------------------------------------------------------------

    public function testActivityEventEmittedWithCorrectDetailsWhenSitesExist(): void
    {
        global $wpdb;
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(1);
        $token  = $this->token(1);

        $request = new WP_REST_Request('POST', '/defyn/v1/security/scan-all');
        $request->set_header('Authorization', 'Bearer ' . $token);
        rest_do_request($request);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = %s",
            'security.scan_all_requested'
        ), ARRAY_A);

        $this->assertCount(1, $rows, 'Exactly one fleet activity event should be emitted');
        $this->assertSame(1, (int) $rows[0]['user_id']);
        $this->assertNull($rows[0]['site_id'], 'Fleet-scoped event must have null site_id');

        $details = json_decode((string) $rows[0]['details'], true);
        $this->assertSame(2, $details['scheduled_count']);
        $this->assertEqualsCanonicalizing([$siteA, $siteB], $details['site_ids']);
    }

    // -------------------------------------------------------------------------
    // 5. 200 no-op + ZERO activity events when user owns 0 sites
    // -------------------------------------------------------------------------

    public function testZeroSitesReturns200AndNoActivityEvent(): void
    {
        global $wpdb;
        $token = $this->token(1); // user 1 has zero sites

        $request = new WP_REST_Request('POST', '/defyn/v1/security/scan-all');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertSame(0, $body['scheduled_count']);
        $this->assertSame([], $body['site_ids']);

        // No fleet activity event should fire on the zero-sites path.
        $logRows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = %s",
            'security.scan_all_requested'
        ));
        $this->assertSame([], $logRows);
    }

    // -------------------------------------------------------------------------
    // 6. Ownership scoping — other user's sites excluded
    // -------------------------------------------------------------------------

    public function testTeamWideFleetIncludesAllSites(): void
    {
        $this->seedSite(1);
        $this->seedSite(1);
        $this->seedSite(2); // user 2's site — included in fleet-wide fan-out
        $token = $this->token(1);

        $request = new WP_REST_Request('POST', '/defyn/v1/security/scan-all');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);

        $body = $response->get_data();
        // Team-wide: per-user filter removed (2026-06-22 SSO spec).
        // All 3 sites (2 for user 1 + 1 for user 2) are fanned-out fleet-wide.
        $this->assertSame(3, $body['scheduled_count'], 'All fleet sites should be fanned out');
    }

    // -------------------------------------------------------------------------
    // 7. Rate limit — 429 after 6th call (limit is 5/HOUR)
    // -------------------------------------------------------------------------

    public function testRateLimit429AfterSixthCall(): void
    {
        $this->seedSite(1);
        $token = $this->token(1);

        for ($i = 0; $i < 5; $i++) {
            $request = new WP_REST_Request('POST', '/defyn/v1/security/scan-all');
            $request->set_header('Authorization', 'Bearer ' . $token);
            $resp = rest_do_request($request);
            $this->assertSame(
                202,
                $resp->get_status(),
                "call #" . ($i + 1) . " should be 202"
            );
        }

        $request = new WP_REST_Request('POST', '/defyn/v1/security/scan-all');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $resp = rest_do_request($request);
        $this->assertSame(429, $resp->get_status());
        $this->assertSame(
            'security.rate_limited',
            $resp->get_data()['error']['code'] ?? null
        );
    }

    // -------------------------------------------------------------------------
    // Helpers (copied from OverviewSyncAllControllerTest)
    // -------------------------------------------------------------------------

    private function seedSite(int $userId): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://ex' . microtime(true) . rand(0, 9999) . '.com',
            'label'      => 'Example',
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function token(int $userId): string
    {
        return (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
    }
}
