<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Jobs\SyncSite;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * @group integration
 */
final class SitesSyncTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }

        // Clear any prior AS rows so as_next_scheduled_action checks are deterministic
        // across tests in this class.
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(SyncSite::HOOK, null, 'defyn');
        }

        do_action('rest_api_init');
    }

    public function testOwnerRequestSchedulesSyncJobReturns202(): void
    {
        $userId = self::factory()->user->create();
        $token  = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
        $siteId = (new SitesRepository())->insertPending($userId, 'https://defyn.test', 'Site', 'PUB', 'ENC');

        $req = new WP_REST_Request('POST', '/defyn/v1/sites/' . $siteId . '/sync');
        $req->set_header('Authorization', 'Bearer ' . $token);
        $r = rest_do_request($req);

        self::assertSame(202, $r->get_status());
        $data = $r->get_data();
        self::assertSame($siteId, $data['site_id']);
        self::assertTrue($data['scheduled']);

        self::assertNotFalse(as_next_scheduled_action(SyncSite::HOOK, [$siteId], 'defyn'));
    }

    public function testTeamMemberCanSyncSite(): void
    {
        // Team-wide: per-user filter removed (2026-06-22 SSO spec).
        $ownerId  = self::factory()->user->create();
        $stranger = self::factory()->user->create();
        $token    = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($stranger);
        $siteId   = (new SitesRepository())->insertPending($ownerId, 'https://defyn.test', '', 'P', 'E');

        $req = new WP_REST_Request('POST', '/defyn/v1/sites/' . $siteId . '/sync');
        $req->set_header('Authorization', 'Bearer ' . $token);
        $r = rest_do_request($req);

        self::assertSame(202, $r->get_status());

        // Job is scheduled — team member access is now granted.
        self::assertNotFalse(as_next_scheduled_action(SyncSite::HOOK, [$siteId], 'defyn'));
    }

    public function testUnauthenticatedReturns401(): void
    {
        $req = new WP_REST_Request('POST', '/defyn/v1/sites/1/sync');
        $r = rest_do_request($req);

        self::assertSame(401, $r->get_status());
    }
}
