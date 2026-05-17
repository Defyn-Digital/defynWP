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
final class SitesCreateCodeLengthTest extends AbstractSchemaTestCase
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

        $this->userId      = self::factory()->user->create(['user_email' => 'codelen@test.test']);
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

    public function testTooShortCodeReturns400InvalidCode(): void
    {
        $r = $this->postSite([
            'url'   => 'https://defyn.test',
            'label' => 'My site',
            'code'  => 'ABC123', // 6 chars
        ]);

        self::assertSame(400, $r->get_status());
        self::assertSame('sites.invalid_code', $r->get_data()['error']['code']);
    }

    public function testTooLongCodeReturns400InvalidCode(): void
    {
        $r = $this->postSite([
            'url'   => 'https://defyn.test',
            'label' => 'My site',
            'code'  => 'ABCDEFGHJKMNPQRSTV', // 18 chars
        ]);

        self::assertSame(400, $r->get_status());
        self::assertSame('sites.invalid_code', $r->get_data()['error']['code']);
    }
}
