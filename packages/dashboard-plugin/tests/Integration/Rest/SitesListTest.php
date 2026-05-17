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
final class SitesListTest extends AbstractSchemaTestCase
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

    public function testListReturnsOnlyOwnerSites(): void
    {
        $owner    = self::factory()->user->create();
        $stranger = self::factory()->user->create();
        $repo = new SitesRepository();
        $repo->insertPending($owner,    'https://a.test', '', 'P', 'E');
        $repo->insertPending($owner,    'https://b.test', '', 'P', 'E');
        $repo->insertPending($stranger, 'https://c.test', '', 'P', 'E');

        $token = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($owner);
        $req = new WP_REST_Request('GET', '/defyn/v1/sites');
        $req->set_header('Authorization', 'Bearer ' . $token);
        $r = rest_do_request($req);

        self::assertSame(200, $r->get_status());
        $data = $r->get_data();
        self::assertArrayHasKey('sites', $data);
        self::assertCount(2, $data['sites']);
        self::assertSame(['https://a.test', 'https://b.test'], array_map(fn ($s) => $s['url'], $data['sites']));
    }

    public function testEmptyListReturnsEmptyArray(): void
    {
        $userId = self::factory()->user->create();
        $token  = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
        $req = new WP_REST_Request('GET', '/defyn/v1/sites');
        $req->set_header('Authorization', 'Bearer ' . $token);
        $r = rest_do_request($req);

        self::assertSame(200, $r->get_status());
        self::assertSame(['sites' => []], $r->get_data());
    }
}
