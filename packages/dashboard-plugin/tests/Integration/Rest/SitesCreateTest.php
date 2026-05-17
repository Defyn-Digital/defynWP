<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * @group integration
 */
final class SitesCreateTest extends AbstractSchemaTestCase
{
    private string $accessToken;
    private int $userId;

    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());

        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        if (!defined('DEFYN_VAULT_KEY')) {
            define('DEFYN_VAULT_KEY', \Defyn\Dashboard\Crypto\Vault::generateKey());
        }

        $this->userId = self::factory()->user->create(['user_email' => 'a@test.test']);
        $this->accessToken = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($this->userId);

        do_action('rest_api_init');
    }

    private function postSite(array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/defyn/v1/sites');
        $req->set_header('Authorization', 'Bearer ' . $this->accessToken);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode($body));
        return rest_do_request($req);
    }

    public function testValidPostReturns202AndCreatesPendingSite(): void
    {
        $r = $this->postSite([
            'url'   => 'https://defyn.test',
            'label' => 'My site',
            'code'  => 'ABCDEFGH2345',
        ]);

        self::assertSame(202, $r->get_status());
        $data = $r->get_data();
        self::assertArrayHasKey('site_id', $data);

        global $wpdb;
        $row = $wpdb->get_row('SELECT * FROM ' . SitesTable::tableName() . ' WHERE id = ' . (int) $data['site_id'], ARRAY_A);
        self::assertSame('pending',                  $row['status']);
        self::assertSame('https://defyn.test',       $row['url']);
        self::assertSame('My site',                  $row['label']);
        self::assertSame((string) $this->userId,     $row['user_id']);
        self::assertNotEmpty($row['our_public_key']);
        self::assertNotEmpty($row['our_private_key']);
    }

    public function testMissingFieldsReturns400(): void
    {
        $r = $this->postSite(['url' => 'https://defyn.test']);
        self::assertSame(400, $r->get_status());
        self::assertSame('sites.missing_fields', $r->get_data()['error']['code']);
    }

    public function testInvalidUrlReturns400(): void
    {
        $r = $this->postSite(['url' => 'http://insecure.test', 'label' => '', 'code' => 'X']);
        self::assertSame(400, $r->get_status());
        self::assertSame('sites.invalid_url', $r->get_data()['error']['code']);
    }

    public function testDuplicateUrlForUserReturns409(): void
    {
        $this->postSite(['url' => 'https://defyn.test', 'label' => '', 'code' => 'X']);
        $r = $this->postSite(['url' => 'https://defyn.test', 'label' => '', 'code' => 'X']);

        self::assertSame(409, $r->get_status());
        self::assertSame('sites.duplicate_url', $r->get_data()['error']['code']);
    }

    public function testUnauthenticatedRequestReturns401(): void
    {
        $req = new WP_REST_Request('POST', '/defyn/v1/sites');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode(['url' => 'https://defyn.test', 'label' => '', 'code' => 'X']));
        $r = rest_do_request($req);

        self::assertSame(401, $r->get_status());
    }
}
