<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * @group integration
 */
final class OverviewSyncAllControllerTest extends AbstractSchemaTestCase
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

        // Clear any prior AS rows so per-test assertions on scheduled actions are
        // deterministic (the underlying actionscheduler_actions table is shared
        // across the test runner — TRUNCATE inside a transaction wouldn't roll
        // back AS state on Kinsta's MySQL).
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('defyn_sync_site', null, 'defyn');
        }

        do_action('rest_api_init');
    }

    public function tearDown(): void
    {
        // Wipe the per-user rate-limit transients so tests start fresh.
        for ($i = 1; $i <= 10; $i++) {
            delete_transient("defyn_rl_overviewSyncAll_{$i}");
        }
        parent::tearDown();
    }

    public function testAuthRequiredReturns401WhenNoBearerToken(): void
    {
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/sync-all');
        $response = rest_do_request($request);

        $this->assertSame(401, $response->get_status());
    }

    public function testHappyPath202WithFullEnvelopeShape(): void
    {
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(1);
        $siteC = $this->seedSite(1);
        $token = $this->token(1);

        $request = new WP_REST_Request('POST', '/defyn/v1/overview/sync-all');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);

        $this->assertSame(202, $response->get_status());
        $body = $response->get_data();
        $this->assertSame(3, $body['scheduled_count']);
        $this->assertEqualsCanonicalizing([$siteA, $siteB, $siteC], $body['site_ids']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $body['scheduled_at']
        );
    }

    public function testZeroSitesReturns200WithEmptyArrays(): void
    {
        global $wpdb;
        $token = $this->token(1); // user 1 has zero sites

        $request = new WP_REST_Request('POST', '/defyn/v1/overview/sync-all');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertSame(0, $body['scheduled_count']);
        $this->assertSame([], $body['site_ids']);

        // No fleet activity event should fire on the zero-sites path
        // (plan-bug trap #4 — no log noise when there's nothing to do).
        $logRows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log
             WHERE event_type = %s",
            'overview.sync_all_requested'
        ));
        $this->assertSame([], $logRows);
    }

    public function testRateLimit429AfterEleventhCall(): void
    {
        $this->seedSite(1);
        $token = $this->token(1);

        for ($i = 0; $i < 10; $i++) {
            $request = new WP_REST_Request('POST', '/defyn/v1/overview/sync-all');
            $request->set_header('Authorization', 'Bearer ' . $token);
            $resp = rest_do_request($request);
            $this->assertSame(
                202,
                $resp->get_status(),
                "call #" . ($i + 1) . " should be 202"
            );
        }

        $request = new WP_REST_Request('POST', '/defyn/v1/overview/sync-all');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $resp = rest_do_request($request);
        $this->assertSame(429, $resp->get_status());
        $this->assertSame(
            'overview.rate_limited',
            $resp->get_data()['error']['code'] ?? null
        );
    }

    public function testOwnershipScopingExcludesOtherUsersSites(): void
    {
        $this->seedSite(1);
        $this->seedSite(1);
        $this->seedSite(2); // user 2's site — must NOT be in user 1's fan-out
        $token = $this->token(1);

        $request = new WP_REST_Request('POST', '/defyn/v1/overview/sync-all');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);

        $body = $response->get_data();
        $this->assertSame(2, $body['scheduled_count']);
    }

    public function testFanOutSchedulesSyncSiteJobPerSite(): void
    {
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(1);
        $token = $this->token(1);

        $request = new WP_REST_Request('POST', '/defyn/v1/overview/sync-all');
        $request->set_header('Authorization', 'Bearer ' . $token);
        rest_do_request($request);

        // Action Scheduler's helper accepts a partial filter; per-arg lookup
        // uses a separate per-id query to confirm the scheduled action exists.
        $argsA = as_get_scheduled_actions([
            'hook' => 'defyn_sync_site',
            'args' => [$siteA],
        ]);
        $argsB = as_get_scheduled_actions([
            'hook' => 'defyn_sync_site',
            'args' => [$siteB],
        ]);
        $this->assertGreaterThanOrEqual(1, count($argsA), "site A should have at least 1 scheduled SyncSite");
        $this->assertGreaterThanOrEqual(1, count($argsB), "site B should have at least 1 scheduled SyncSite");
    }

    public function testActivityEventEmittedWithCorrectDetails(): void
    {
        global $wpdb;
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(1);
        $token = $this->token(1);

        $request = new WP_REST_Request('POST', '/defyn/v1/overview/sync-all');
        $request->set_header('Authorization', 'Bearer ' . $token);
        rest_do_request($request);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log
             WHERE event_type = %s",
            'overview.sync_all_requested'
        ), ARRAY_A);

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['user_id']);
        $this->assertNull($rows[0]['site_id']); // fleet-scoped — plan-bug trap #3

        $details = json_decode((string) $rows[0]['details'], true);
        $this->assertSame(2, $details['scheduled_count']);
        $this->assertEqualsCanonicalizing([$siteA, $siteB], $details['site_ids']);
    }

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
