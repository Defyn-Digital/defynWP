<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * @group integration
 */
final class SitesPluginsRefreshTest extends AbstractSchemaTestCase
{
    private const REFRESH_HOOK = 'defyn_refresh_site_plugins';

    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_activity_log');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }

        // Clear any prior AS rows so as_next_scheduled_action checks are deterministic.
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::REFRESH_HOOK, null, 'defyn');
        }

        do_action('rest_api_init');
    }

    /** @return array{userId:int, siteId:int, jwt:string} */
    private function setupOwnedSite(): array
    {
        $userId = self::factory()->user->create();
        $sites  = new SitesRepository();
        $siteId = $sites->insertPending(
            $userId,
            'https://demo.test',
            'Demo',
            base64_encode(random_bytes(32)),
            'cipher',
        );
        $sites->markActive($siteId, base64_encode(random_bytes(32)));

        $jwt = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);

        // Clear the rate-limit transient for this (user, site) so a leftover from a
        // prior test in this class can't make `setupOwnedSite()` start counting at 6.
        delete_transient(sprintf('defyn_rl_plugins_refresh_%d_%d', $userId, $siteId));

        return ['userId' => $userId, 'siteId' => $siteId, 'jwt' => $jwt];
    }

    public function testReturns202SchedulesJobAndLogsEvent(): void
    {
        $ctx = $this->setupOwnedSite();

        $req = new WP_REST_Request('POST', '/defyn/v1/sites/' . $ctx['siteId'] . '/plugins/refresh');
        $req->set_header('Authorization', 'Bearer ' . $ctx['jwt']);

        $res = rest_do_request($req);

        self::assertSame(202, $res->get_status());
        self::assertSame($ctx['siteId'], $res->get_data()['site_id']);
        self::assertTrue($res->get_data()['scheduled']);

        self::assertNotFalse(as_next_scheduled_action(self::REFRESH_HOOK, [$ctx['siteId']], 'defyn'));

        global $wpdb;
        $event = $wpdb->get_row(
            'SELECT event_type, user_id, site_id FROM ' . ActivityLogTable::tableName() .
            ' ORDER BY id DESC LIMIT 1',
            ARRAY_A,
        );
        self::assertSame('plugin_inventory.refresh_requested', $event['event_type']);
        // Locks in the (userId, siteId) arg order on ActivityLogger::log — the
        // arg-flip bug that surfaced in Task 7 should not reappear here.
        self::assertSame((string) $ctx['userId'], (string) $event['user_id']);
        self::assertSame((string) $ctx['siteId'], (string) $event['site_id']);
    }

    public function testReturns404WhenSiteNotOwnedByUser(): void
    {
        $ownerId  = self::factory()->user->create();
        $stranger = self::factory()->user->create();
        $sites    = new SitesRepository();
        $siteId   = $sites->insertPending(
            $ownerId,
            'https://demo.test',
            'Demo',
            base64_encode(random_bytes(32)),
            'cipher',
        );
        $sites->markActive($siteId, base64_encode(random_bytes(32)));

        $jwt = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($stranger);

        $req = new WP_REST_Request('POST', '/defyn/v1/sites/' . $siteId . '/plugins/refresh');
        $req->set_header('Authorization', 'Bearer ' . $jwt);
        $res = rest_do_request($req);

        self::assertSame(404, $res->get_status());
        self::assertSame('sites.not_found', $res->get_data()['error']['code']);
        self::assertFalse(as_next_scheduled_action(self::REFRESH_HOOK, [$siteId], 'defyn'));
    }

    public function testUnauthenticatedReturns401(): void
    {
        $req = new WP_REST_Request('POST', '/defyn/v1/sites/1/plugins/refresh');
        $res = rest_do_request($req);

        self::assertSame(401, $res->get_status());
        self::assertSame('auth.missing_token', $res->get_data()['error']['code']);
    }

    public function testRateLimitedAfterSixRequests(): void
    {
        $ctx = $this->setupOwnedSite();

        for ($i = 0; $i < 6; $i++) {
            $req = new WP_REST_Request('POST', '/defyn/v1/sites/' . $ctx['siteId'] . '/plugins/refresh');
            $req->set_header('Authorization', 'Bearer ' . $ctx['jwt']);
            $res = rest_do_request($req);
            self::assertSame(202, $res->get_status(), "request {$i} should succeed");
        }

        $req = new WP_REST_Request('POST', '/defyn/v1/sites/' . $ctx['siteId'] . '/plugins/refresh');
        $req->set_header('Authorization', 'Bearer ' . $ctx['jwt']);
        $res = rest_do_request($req);
        self::assertSame(429, $res->get_status());
        self::assertSame('plugins.rate_limited', $res->get_data()['error']['code']);
    }
}
