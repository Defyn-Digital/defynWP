<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * @group integration
 */
final class SitesPluginsListTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_site_plugins');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . SitePluginsTable::tableName());
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');
    }

    private function makeSite(int $userId, string $url = 'https://x.test'): int
    {
        return (new SitesRepository())->insertPending(
            $userId,
            $url,
            'X',
            base64_encode(random_bytes(32)),
            'cipher',
        );
    }

    public function testReturns401WithoutJwt(): void
    {
        $owner = self::factory()->user->create();
        $site  = $this->makeSite($owner);

        $req = new WP_REST_Request('GET', '/defyn/v1/sites/' . $site . '/plugins');
        $res = rest_do_request($req);

        self::assertSame(401, $res->get_status());
        $body = $res->get_data();
        self::assertSame('auth.missing_token', $body['error']['code']);
    }

    public function testReturns404WhenSiteNotOwnedByUser(): void
    {
        $owner    = self::factory()->user->create();
        $stranger = self::factory()->user->create();
        $site     = $this->makeSite($owner);

        $token = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($stranger);
        $req   = new WP_REST_Request('GET', '/defyn/v1/sites/' . $site . '/plugins');
        $req->set_header('Authorization', 'Bearer ' . $token);

        $res = rest_do_request($req);

        self::assertSame(404, $res->get_status());
        self::assertSame('sites.not_found', $res->get_data()['error']['code']);
    }

    public function testReturnsPluginsForOwnedSite(): void
    {
        $owner = self::factory()->user->create();
        $site  = $this->makeSite($owner);

        (new SitePluginsRepository())->replaceForSite($site, [
            ['slug' => 'a.php', 'name' => 'A', 'version' => '1.0', 'update_available' => true,  'update_version' => '1.1'],
            ['slug' => 'b.php', 'name' => 'B', 'version' => '2.0', 'update_available' => false, 'update_version' => null],
        ], gmdate('Y-m-d H:i:s'));

        $token = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($owner);
        $req   = new WP_REST_Request('GET', '/defyn/v1/sites/' . $site . '/plugins');
        $req->set_header('Authorization', 'Bearer ' . $token);

        $res  = rest_do_request($req);
        $body = $res->get_data();

        self::assertSame(200, $res->get_status());
        self::assertSame(2, $body['total']);
        self::assertCount(2, $body['plugins']);
        self::assertSame('a.php', $body['plugins'][0]['slug']);
        self::assertTrue($body['plugins'][0]['update_available']);
        self::assertNotNull($body['last_synced_at']);

        // P2.2 — schema v3 fields must reach the SPA, otherwise the SPA's
        // polling state machine can never observe queued/updating/failed.
        self::assertArrayHasKey('update_state', $body['plugins'][0]);
        self::assertSame('idle', $body['plugins'][0]['update_state']);
        self::assertArrayHasKey('last_update_error', $body['plugins'][0]);
        self::assertArrayHasKey('last_update_attempt_at', $body['plugins'][0]);
    }
}
