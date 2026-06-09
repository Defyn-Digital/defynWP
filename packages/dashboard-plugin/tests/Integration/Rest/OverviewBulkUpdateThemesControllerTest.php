<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P2.8 — Tests for POST /defyn/v1/overview/bulk-update-themes.
 *
 * Mirror of P2.7's OverviewBulkUpdatePluginsControllerTest with theme swap:
 * - field key STAYS `slug` (not stylesheet)
 * - skip reason `theme_not_found` (not plugin_not_found)
 * - activity event `overview.bulk_theme_update_requested`
 * - AS hook `defyn_update_site_theme`
 * - RateLimit::bulkThemeUpdate (5/HOUR), bucket prefix defyn_rl_bulkThemeUpdate_
 *
 * @group integration
 */
final class OverviewBulkUpdateThemesControllerTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        $this->freshlyActivate('defyn_site_themes');
        $this->freshlyActivate('defyn_activity_log');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_activity_log");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_themes");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");
        // phpcs:enable WordPress.DB.PreparedSQL

        // Wipe the per-user rate-limit transients so tests start fresh.
        for ($i = 1; $i <= 10; $i++) {
            delete_transient("defyn_rl_bulkThemeUpdate_{$i}");
        }

        // Clear any prior scheduled actions from previous tests.
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('defyn_update_site_theme');
        }

        do_action('rest_api_init');
    }

    public function testAuthRequiredReturns401WhenNoBearerToken(): void
    {
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [['site_id' => 1, 'slug' => 'astra']]]));
        $response = rest_do_request($request);
        $this->assertSame(401, $response->get_status());
    }

    public function testHappyPath202WithScheduledPairs(): void
    {
        $siteA = $this->seedSite(1, 'SmartCoding');
        $this->seedTheme($siteA, 'astra',   'Astra',   '4.6.3', '4.7.0', true);
        $this->seedTheme($siteA, 'blocksy', 'Blocksy', '2.0.1', '2.0.2', true);

        $token = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [
            ['site_id' => $siteA, 'slug' => 'astra'],
            ['site_id' => $siteA, 'slug' => 'blocksy'],
        ]]));
        $response = rest_do_request($request);

        $this->assertSame(202, $response->get_status());
        $body = $response->get_data();
        $this->assertSame(2, $body['scheduled_count']);
        $this->assertSame(0, $body['skipped_count']);
        $this->assertCount(2, $body['scheduled_pairs']);
        $this->assertSame([], $body['skipped_pairs']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $body['scheduled_at']);

        $slugs = array_column($body['scheduled_pairs'], 'slug');
        sort($slugs);
        $this->assertSame(['astra', 'blocksy'], $slugs);
    }

    public function testEmptyUpdatesReturns400(): void
    {
        $token = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => []]));
        $response = rest_do_request($request);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('bulk.empty_updates', $response->get_data()['error']['code'] ?? null);
    }

    public function testRateLimit429AfterSixthCall(): void
    {
        $token = $this->token(1);

        // Each call carries an invalid pair so AS jobs aren't queued, but the
        // rate-limit bucket still increments because it's checked at the
        // permission_callback layer BEFORE the controller validates pairs.
        for ($i = 0; $i < 5; $i++) {
            $req = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
            $req->set_header('Authorization', 'Bearer ' . $token);
            $req->set_header('Content-Type', 'application/json');
            $req->set_body(json_encode(['updates' => [['site_id' => 999, 'slug' => 'nonexistent']]]));
            $resp = rest_do_request($req);
            $this->assertNotSame(429, $resp->get_status(), 'call #' . ($i + 1) . ' should not be 429');
        }

        $req = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $req->set_header('Authorization', 'Bearer ' . $token);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode(['updates' => [['site_id' => 999, 'slug' => 'nonexistent']]]));
        $resp = rest_do_request($req);
        $this->assertSame(429, $resp->get_status());
        $this->assertSame('bulk.rate_limited', $resp->get_data()['error']['code'] ?? null);
    }

    public function testSkipsPairsNotOwnedOrWithoutUpdate(): void
    {
        $siteOwned    = $this->seedSite(1, 'Owned');
        $siteOtherUsr = $this->seedSite(2, 'NotMine');
        // Owned site has only 'astra' with no update available
        $this->seedTheme($siteOwned, 'astra', 'Astra', '4.7.0', null, false);

        $token = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [
            ['site_id' => $siteOtherUsr, 'slug' => 'astra'],          // site_not_owned
            ['site_id' => $siteOwned,    'slug' => 'missing-theme'],  // theme_not_found
            ['site_id' => $siteOwned,    'slug' => 'astra'],          // no_update_available
        ]]));
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertSame(0, $body['scheduled_count']);
        $this->assertSame(3, $body['skipped_count']);

        // Map by (slug + site_id) since slug 'astra' appears for two different
        // sites with different skip reasons.
        $reasonsBySlug = [];
        foreach ($body['skipped_pairs'] as $skipped) {
            $reasonsBySlug[$skipped['slug'] . ':' . $skipped['site_id']] = $skipped['reason'];
        }
        $this->assertSame('site_not_owned',      $reasonsBySlug['astra:' . $siteOtherUsr]);
        $this->assertSame('theme_not_found',     $reasonsBySlug['missing-theme:' . $siteOwned]);
        $this->assertSame('no_update_available', $reasonsBySlug['astra:' . $siteOwned]);
    }

    public function testFanOutSchedulesPerPair(): void
    {
        $siteA = $this->seedSite(1, 'SmartCoding');
        $this->seedTheme($siteA, 'astra', 'Astra', '4.6.3', '4.7.0', true);

        $token = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [
            ['site_id' => $siteA, 'slug' => 'astra'],
        ]]));
        rest_do_request($request);

        $astraJobs = as_get_scheduled_actions([
            'hook' => 'defyn_update_site_theme',
            'args' => [$siteA, 'astra', 0],
        ]);
        $this->assertGreaterThanOrEqual(1, count($astraJobs));
    }

    public function testActivityEventEmittedWithCorrectDetails(): void
    {
        global $wpdb;
        $siteA = $this->seedSite(1, 'SmartCoding');
        $this->seedTheme($siteA, 'astra', 'Astra', '4.6.3', '4.7.0', true);

        $token = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [
            ['site_id' => $siteA, 'slug' => 'astra'],
        ]]));
        rest_do_request($request);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = %s AND user_id = %d",
            'overview.bulk_theme_update_requested',
            1
        ), ARRAY_A);

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['user_id']);
        $this->assertNull($rows[0]['site_id']); // fleet-scoped — guardrail #4

        $details = json_decode((string) $rows[0]['details'], true);
        $this->assertSame(1, $details['scheduled_count']);
        $this->assertSame(0, $details['skipped_count']);
        $this->assertCount(1, $details['pairs']);
        $this->assertSame($siteA,  $details['pairs'][0]['site_id']);
        $this->assertSame('astra', $details['pairs'][0]['slug']);
    }

    public function testZeroValidPairsReturns200AndNoActivityEvent(): void
    {
        global $wpdb;

        $token = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        // 3 invalid pairs (no seeded data) → all site_not_owned
        $request->set_body(json_encode(['updates' => [
            ['site_id' => 999, 'slug' => 'a'],
            ['site_id' => 998, 'slug' => 'b'],
            ['site_id' => 997, 'slug' => 'c'],
        ]]));
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertSame(0, $body['scheduled_count']);
        $this->assertSame(3, $body['skipped_count']);

        // Guardrail #2 — activity event ONLY fires when scheduled_count > 0.
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = 'overview.bulk_theme_update_requested'"
        );
        $this->assertSame(0, $count);
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

    private function seedTheme(int $siteId, string $slug, string $name, string $version, ?string $updateVersion, bool $updateAvailable): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'defyn_site_themes', [
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
