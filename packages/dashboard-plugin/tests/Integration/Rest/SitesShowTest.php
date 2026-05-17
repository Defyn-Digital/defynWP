<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * @group integration
 */
final class SitesShowTest extends AbstractSchemaTestCase
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
        do_action('rest_api_init');
    }

    public function testReturnsSiteJsonForOwner(): void
    {
        $userId = self::factory()->user->create();
        $token = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
        $siteId = (new SitesRepository())->insertPending($userId, 'https://defyn.test', 'Site', 'PUB', 'ENC');

        $req = new WP_REST_Request('GET', '/defyn/v1/sites/' . $siteId);
        $req->set_header('Authorization', 'Bearer ' . $token);
        $r = rest_do_request($req);

        self::assertSame(200, $r->get_status());
        $data = $r->get_data();
        self::assertSame($siteId,             $data['id']);
        self::assertSame('https://defyn.test', $data['url']);
        self::assertSame('pending',           $data['status']);
        self::assertArrayNotHasKey('our_private_key', $data);
    }

    public function testReturns404ForOtherUsersSite(): void
    {
        $ownerId   = self::factory()->user->create();
        $stranger  = self::factory()->user->create();
        $token     = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($stranger);
        $siteId    = (new SitesRepository())->insertPending($ownerId, 'https://defyn.test', '', 'P', 'E');

        $req = new WP_REST_Request('GET', '/defyn/v1/sites/' . $siteId);
        $req->set_header('Authorization', 'Bearer ' . $token);
        $r = rest_do_request($req);

        self::assertSame(404, $r->get_status());
        self::assertSame('sites.not_found', $r->get_data()['error']['code']);
    }

    public function testReturns404ForNonExistentId(): void
    {
        $userId = self::factory()->user->create();
        $token  = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);

        $req = new WP_REST_Request('GET', '/defyn/v1/sites/9999');
        $req->set_header('Authorization', 'Bearer ' . $token);
        $r = rest_do_request($req);

        self::assertSame(404, $r->get_status());
    }
}
