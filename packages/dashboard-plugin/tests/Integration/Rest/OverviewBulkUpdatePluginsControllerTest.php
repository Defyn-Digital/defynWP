<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * @group integration
 */
final class OverviewBulkUpdatePluginsControllerTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        $this->freshlyActivate('defyn_site_plugins');
        $this->freshlyActivate('defyn_bulk_jobs');
        $this->freshlyActivate('defyn_bulk_job_items');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_job_items");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_jobs");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_activity_log");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_plugins");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");
        // phpcs:enable WordPress.DB.PreparedSQL

        // Wipe the per-user rate-limit transients so tests start fresh.
        for ($i = 1; $i <= 10; $i++) {
            delete_transient("defyn_rl_bulkPluginUpdate_{$i}");
        }

        do_action('rest_api_init');
    }

    public function testAuthRequiredReturns401WhenNoBearerToken(): void
    {
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [['site_id' => 1, 'slug' => 'akismet']]]));
        $response = rest_do_request($request);
        $this->assertSame(401, $response->get_status());
    }

    public function testHappyPath202WithScheduledPairs(): void
    {
        $siteA = $this->seedSite(1, 'A');
        $siteB = $this->seedSite(1, 'B');
        $this->seedPlugin($siteA, 'akismet', 'Akismet', '5.3', '5.3.1', true);
        $this->seedPlugin($siteA, 'yoast',   'Yoast',   '22.5', '22.6', true);
        $this->seedPlugin($siteB, 'jetpack', 'Jetpack', '13.1', '13.2', true);

        $token = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [
            ['site_id' => $siteA, 'slug' => 'akismet'],
            ['site_id' => $siteA, 'slug' => 'yoast'],
            ['site_id' => $siteB, 'slug' => 'jetpack'],
        ]]));
        $response = rest_do_request($request);

        $this->assertSame(202, $response->get_status());
        $body = $response->get_data();
        $this->assertSame(3, $body['scheduled_count']);
        $this->assertSame(0, $body['skipped_count']);
        $this->assertCount(3, $body['scheduled_pairs']);
        $this->assertSame([], $body['skipped_pairs']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $body['scheduled_at']);
    }

    public function testEmptyUpdatesReturns400(): void
    {
        $token = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => []]));
        $response = rest_do_request($request);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('bulk.empty_updates', $response->get_data()['error']['code'] ?? null);
    }

    public function testRateLimit429AfterSixthCall(): void
    {
        $siteA = $this->seedSite(1, 'A');
        $this->seedPlugin($siteA, 'akismet', 'Akismet', '5.3', '5.3.1', true);
        $token = $this->token(1);

        for ($i = 0; $i < 5; $i++) {
            $req = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
            $req->set_header('Authorization', 'Bearer ' . $token);
            $req->set_header('Content-Type', 'application/json');
            $req->set_body(json_encode(['updates' => [['site_id' => $siteA, 'slug' => 'akismet']]]));
            $resp = rest_do_request($req);
            $this->assertSame(202, $resp->get_status(), 'call #' . ($i + 1) . ' should be 202');
        }

        $req = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $req->set_header('Authorization', 'Bearer ' . $token);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode(['updates' => [['site_id' => $siteA, 'slug' => 'akismet']]]));
        $resp = rest_do_request($req);
        $this->assertSame(429, $resp->get_status());
        $this->assertSame('bulk.rate_limited', $resp->get_data()['error']['code'] ?? null);
    }

    public function testSkipsPairsNotOwnedOrWithoutUpdate(): void
    {
        $siteOwned    = $this->seedSite(1, 'Owned');
        $siteOtherUsr = $this->seedSite(2, 'NotMine');
        $this->seedPlugin($siteOwned,    'akismet',     'Akismet',  '5.3', '5.3.1', true);  // valid
        $this->seedPlugin($siteOwned,    'no-upd',      'NoUpdate', '1.0', null,    false); // no_update_available
        $this->seedPlugin($siteOtherUsr, 'wpml',        'WPML',     '4.7', '4.8',   true);  // owned by user 2 — site_not_owned

        $token = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [
            ['site_id' => $siteOwned,    'slug' => 'akismet'],     // SCHEDULED
            ['site_id' => $siteOwned,    'slug' => 'no-upd'],      // no_update_available
            ['site_id' => $siteOwned,    'slug' => 'not-in-inv'],  // plugin_not_found
            ['site_id' => $siteOtherUsr, 'slug' => 'wpml'],        // site_not_owned
        ]]));
        $response = rest_do_request($request);

        $this->assertSame(202, $response->get_status());
        $body = $response->get_data();
        $this->assertSame(1, $body['scheduled_count']);
        $this->assertSame(3, $body['skipped_count']);

        $reasons = array_column($body['skipped_pairs'], 'reason', 'slug');
        $this->assertSame('no_update_available', $reasons['no-upd']);
        $this->assertSame('plugin_not_found',    $reasons['not-in-inv']);
        $this->assertSame('site_not_owned',      $reasons['wpml']);
    }

    public function testFanOutSchedulesPerPair(): void
    {
        $siteA = $this->seedSite(1, 'A');
        $this->seedPlugin($siteA, 'akismet', 'Akismet', '5.3', '5.3.1', true);
        $this->seedPlugin($siteA, 'yoast',   'Yoast',   '22.5', '22.6', true);

        $token = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [
            ['site_id' => $siteA, 'slug' => 'akismet'],
            ['site_id' => $siteA, 'slug' => 'yoast'],
        ]]));
        rest_do_request($request);

        global $wpdb;
        $akismetItemId = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}defyn_bulk_job_items WHERE resource_slug = 'akismet'"
        );
        $akismetJobs = as_get_scheduled_actions([
            'hook' => 'defyn_update_site_plugin',
            'args' => [$siteA, 'akismet', 0, $akismetItemId],
        ]);
        $yoastItemId = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}defyn_bulk_job_items WHERE resource_slug = 'yoast'"
        );
        $yoastJobs = as_get_scheduled_actions([
            'hook' => 'defyn_update_site_plugin',
            'args' => [$siteA, 'yoast', 0, $yoastItemId],
        ]);
        $this->assertGreaterThanOrEqual(1, count($akismetJobs));
        $this->assertGreaterThanOrEqual(1, count($yoastJobs));
    }

    public function testActivityEventEmittedWithCorrectDetails(): void
    {
        global $wpdb;
        $siteA = $this->seedSite(1, 'A');
        $this->seedPlugin($siteA, 'akismet', 'Akismet', '5.3', '5.3.1', true);
        $this->seedPlugin($siteA, 'yoast',   'Yoast',   '22.5', '22.6', true);

        $token = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [
            ['site_id' => $siteA, 'slug' => 'akismet'],
            ['site_id' => $siteA, 'slug' => 'yoast'],
        ]]));
        rest_do_request($request);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = %s",
            'overview.bulk_plugin_update_requested'
        ), ARRAY_A);

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['user_id']);
        $this->assertNull($rows[0]['site_id']); // fleet-scoped — trap #4

        $details = json_decode((string) $rows[0]['details'], true);
        $this->assertSame(2, $details['scheduled_count']);
        $this->assertSame(0, $details['skipped_count']);
        $this->assertCount(2, $details['pairs']);
    }

    public function testZeroValidPairsReturns200AndNoActivityEvent(): void
    {
        global $wpdb;
        $siteOwned    = $this->seedSite(1, 'Owned');
        $siteOtherUsr = $this->seedSite(2, 'NotMine');
        $this->seedPlugin($siteOwned, 'no-upd', 'NoUpdate', '1.0', null, false);
        $this->seedPlugin($siteOtherUsr, 'wpml', 'WPML', '4.7', '4.8', true);

        $token = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [
            ['site_id' => $siteOwned,    'slug' => 'no-upd'],    // no_update_available
            ['site_id' => $siteOwned,    'slug' => 'ghost'],     // plugin_not_found
            ['site_id' => $siteOtherUsr, 'slug' => 'wpml'],      // site_not_owned
        ]]));
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertSame(0, $body['scheduled_count']);
        $this->assertSame(3, $body['skipped_count']);

        // Plan-bug trap #5 — guard if (count > 0) before logging.
        $logRows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = %s",
            'overview.bulk_plugin_update_requested'
        ));
        $this->assertSame([], $logRows);
    }

    public function testHappyPathCreatesJobAndItemsAndReturnsJobId(): void
    {
        $siteA = $this->seedSite(1, 'SmartCoding');
        $this->seedPlugin($siteA, 'akismet', 'Akismet', '5.3', '5.3.1', true);
        $this->seedPlugin($siteA, 'yoast',   'Yoast',   '22.5', '22.6', true);

        $token   = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [
            ['site_id' => $siteA, 'slug' => 'akismet'],
            ['site_id' => $siteA, 'slug' => 'yoast'],
        ]]));
        $response = rest_do_request($request);
        $body     = $response->get_data();

        $this->assertSame(202, $response->get_status());
        $this->assertIsInt($body['job_id']);

        global $wpdb;
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_bulk_jobs WHERE id = %d",
            $body['job_id']
        ), ARRAY_A);
        $this->assertSame('plugin_update', $job['kind']);
        $this->assertSame('2', $job['scheduled_count']);

        $itemCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_bulk_job_items WHERE job_id = %d AND state = 'queued'",
            $body['job_id']
        ));
        $this->assertSame(2, $itemCount);
    }

    public function testZeroValidPairsReturnsNullJobIdAndNoJobRow(): void
    {
        $token   = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [['site_id' => 999, 'slug' => 'ghost']]]));
        $response = rest_do_request($request);
        $body     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertNull($body['job_id']);

        global $wpdb;
        $jobCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}defyn_bulk_jobs");
        $this->assertSame(0, $jobCount);
    }

    private function seedSite(int $userId, string $label): int
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://ex-' . uniqid() . '.com',
            'label'      => $label,
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    private function seedPlugin(int $siteId, string $slug, string $name, string $version, ?string $updateVersion, bool $updateAvailable): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'defyn_site_plugins', [
            'site_id'          => $siteId,
            'slug'             => $slug,
            'name'             => $name,
            'version'          => $version,
            'update_version'   => $updateVersion,
            'update_available' => $updateAvailable ? 1 : 0,
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    private function token(int $userId): string
    {
        return (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
    }
}
