<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Jobs\UpdateSitePlugin;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * @group integration
 *
 * End-to-end coverage for POST /defyn/v1/sites/{id}/plugins/{slug}/update.
 *
 * Mirrors SitesPluginsRefreshTest's JWT bootstrap (TokenService::issueAccess +
 * Bearer header on the WP_REST_Request) so the live RateLimit::pluginsUpdate
 * permission_callback chain is exercised end-to-end, not bypassed.
 *
 * Uses the `pre_as_schedule_single_action` filter (same pattern as
 * UpdateSitePluginTest's retry test) to capture the scheduled AS action without
 * actually persisting it — keeps the assertions deterministic and the suite
 * fast.
 *
 * Spec: docs/superpowers/specs/2026-06-05-p2-2-plugin-updates-design.md §7.1
 */
final class SitesPluginsUpdateTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_site_plugins');
        $this->freshlyActivate('defyn_activity_log');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . SitePluginsTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }

        // Burn any leftover rate-limit transients between tests. The bucket key
        // is per (userId, siteId, slug) and each test mints a fresh userId, so
        // collisions are unlikely — but if a future test reuses an id, the 6/hour
        // ceiling would start counting from a stale value.
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_pluginsUpdate_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_defyn_rl_pluginsUpdate_%'");
        wp_cache_flush();

        do_action('rest_api_init');
    }

    /** @return array{userId:int, siteId:int, jwt:string} */
    private function setupOwnedSite(): array
    {
        $userId = self::factory()->user->create();
        $sites  = new SitesRepository();
        $siteId = $sites->insertPending(
            $userId,
            'https://smartcoding.test',
            'SmartCoding',
            base64_encode(random_bytes(32)),
            'cipher',
        );
        $sites->markActive($siteId, base64_encode(random_bytes(32)));

        $jwt = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);

        return ['userId' => $userId, 'siteId' => $siteId, 'jwt' => $jwt];
    }

    /**
     * Insert a plugin row. Defaults match the success-path preconditions:
     * an update is available (5.7 → 5.8) and the row is idle.
     */
    private function seedPluginRow(
        int $siteId,
        string $slug = 'akismet',
        int $updateAvailable = 1,
        string $updateState = 'idle',
    ): void {
        global $wpdb;
        $wpdb->insert(SitePluginsTable::tableName(), [
            'site_id'          => $siteId,
            'slug'             => $slug,
            'name'             => ucfirst($slug),
            'version'          => '5.7',
            'update_available' => $updateAvailable,
            'update_version'   => $updateAvailable === 1 ? '5.8' : null,
            'update_state'     => $updateState,
            'last_seen_at'     => '2026-06-06 00:00:00',
            'created_at'       => '2026-06-06 00:00:00',
            'updated_at'       => '2026-06-06 00:00:00',
        ]);
    }

    public function testSuccessReturns202AndSchedulesJob(): void
    {
        $ctx = $this->setupOwnedSite();
        $this->seedPluginRow($ctx['siteId'], 'akismet', 1, 'idle');

        // Capture the AS action without persisting it. Returning a non-null
        // value short-circuits ActionScheduler::schedule_single_action() and
        // pretends an action id came back — matches Task 11's retry test.
        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['when' => $when, 'hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $req = new WP_REST_Request(
            'POST',
            '/defyn/v1/sites/' . $ctx['siteId'] . '/plugins/akismet/update',
        );
        $req->set_header('Authorization', 'Bearer ' . $ctx['jwt']);

        $res = rest_do_request($req);

        self::assertSame(202, $res->get_status());
        $body = $res->get_data();
        self::assertTrue($body['scheduled']);
        self::assertSame($ctx['siteId'], $body['site_id']);
        self::assertSame('akismet', $body['slug']);

        // Optimistic write — row flipped from 'idle' → 'queued' before the AS
        // job ran. This is what the SPA polls for to render the spinner.
        $row = (new SitePluginsRepository())->findRowForSiteAndSlug($ctx['siteId'], 'akismet');
        self::assertNotNull($row);
        self::assertSame('queued', $row['update_state']);
        self::assertNull($row['last_update_error']);
        self::assertNotNull($row['last_update_attempt_at']);

        // AS action scheduled with the right hook + args.
        self::assertCount(1, $scheduled);
        self::assertSame(UpdateSitePlugin::HOOK, $scheduled[0]['hook']);
        self::assertSame([$ctx['siteId'], 'akismet', 0], $scheduled[0]['args']);
        self::assertEqualsWithDelta(time(), $scheduled[0]['when'], 5);

        // plugin_update.requested logged with the right user+site+context.
        // Locks in the ActivityLogger::log(userId, siteId, ...) arg order — same
        // flip bug that Task 7 caught.
        global $wpdb;
        $event = $wpdb->get_row(
            'SELECT user_id, site_id, event_type, details FROM ' . ActivityLogTable::tableName() .
            ' ORDER BY id DESC LIMIT 1',
            ARRAY_A,
        );
        self::assertSame('plugin_update.requested', $event['event_type']);
        self::assertSame((string) $ctx['userId'], (string) $event['user_id']);
        self::assertSame((string) $ctx['siteId'], (string) $event['site_id']);
        $details = json_decode((string) $event['details'], true);
        self::assertSame('akismet', $details['slug']);
        self::assertSame('5.7', $details['current_version']);
        self::assertSame('5.8', $details['target_version']);
    }

    public function testSiteNotOwnedReturns404(): void
    {
        // Owner has the site; stranger holds the JWT. The 404 envelope mirrors
        // the unauthorized-lookup shape used by SitesShowController so we don't
        // leak existence to a non-owner (anti-enumeration).
        $ownerId  = self::factory()->user->create();
        $stranger = self::factory()->user->create();
        $sites    = new SitesRepository();
        $siteId   = $sites->insertPending(
            $ownerId,
            'https://smartcoding.test',
            'SmartCoding',
            base64_encode(random_bytes(32)),
            'cipher',
        );
        $sites->markActive($siteId, base64_encode(random_bytes(32)));
        $this->seedPluginRow($siteId, 'akismet', 1, 'idle');

        $jwt = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($stranger);

        $req = new WP_REST_Request('POST', '/defyn/v1/sites/' . $siteId . '/plugins/akismet/update');
        $req->set_header('Authorization', 'Bearer ' . $jwt);
        $res = rest_do_request($req);

        self::assertSame(404, $res->get_status());
        self::assertSame('sites.not_found', $res->get_data()['error']['code']);

        // No optimistic write, no log event.
        $row = (new SitePluginsRepository())->findRowForSiteAndSlug($siteId, 'akismet');
        self::assertSame('idle', $row['update_state']);
    }

    public function testPluginNotInInventoryReturns404(): void
    {
        $ctx = $this->setupOwnedSite();
        // No plugin row seeded — inventory lookup must miss.

        $req = new WP_REST_Request(
            'POST',
            '/defyn/v1/sites/' . $ctx['siteId'] . '/plugins/jetpack/update',
        );
        $req->set_header('Authorization', 'Bearer ' . $ctx['jwt']);
        $res = rest_do_request($req);

        self::assertSame(404, $res->get_status());
        self::assertSame('plugins.not_found_in_inventory', $res->get_data()['error']['code']);
    }

    public function testNoUpdateAvailableReturns409(): void
    {
        $ctx = $this->setupOwnedSite();
        $this->seedPluginRow($ctx['siteId'], 'akismet', updateAvailable: 0, updateState: 'idle');

        $req = new WP_REST_Request(
            'POST',
            '/defyn/v1/sites/' . $ctx['siteId'] . '/plugins/akismet/update',
        );
        $req->set_header('Authorization', 'Bearer ' . $ctx['jwt']);
        $res = rest_do_request($req);

        self::assertSame(409, $res->get_status());
        self::assertSame('plugins.no_update_available', $res->get_data()['error']['code']);

        // Row untouched.
        $row = (new SitePluginsRepository())->findRowForSiteAndSlug($ctx['siteId'], 'akismet');
        self::assertSame('idle', $row['update_state']);
    }

    public function testAlreadyInProgressReturns409(): void
    {
        $ctx = $this->setupOwnedSite();
        $this->seedPluginRow($ctx['siteId'], 'akismet', updateAvailable: 1, updateState: 'queued');

        $req = new WP_REST_Request(
            'POST',
            '/defyn/v1/sites/' . $ctx['siteId'] . '/plugins/akismet/update',
        );
        $req->set_header('Authorization', 'Bearer ' . $ctx['jwt']);
        $res = rest_do_request($req);

        self::assertSame(409, $res->get_status());
        self::assertSame('plugins.update_already_in_progress', $res->get_data()['error']['code']);
    }
}
