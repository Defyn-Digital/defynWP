<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P4.2 — GET /defyn/v1/security
 *
 * Mirrors MonitoringController tests: direct payload (no {data} envelope),
 * 30/min per-user bucket, 401 without auth.
 *
 * @group integration
 */
final class SecurityFleetTest extends AbstractSchemaTestCase
{
    private int $userId;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');

        $this->userId = self::factory()->user->create();
        $this->token  = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($this->userId);
    }

    // -------------------------------------------------------------------------
    // 1. 200 + direct payload (summary + sites keys present)
    // -------------------------------------------------------------------------

    public function testAuthenticatedGetReturns200DirectPayload(): void
    {
        $req = new WP_REST_Request('GET', '/defyn/v1/security');
        $req->set_header('Authorization', 'Bearer ' . $this->token);
        $res = rest_do_request($req);

        self::assertSame(200, $res->get_status());
        $data = $res->get_data();
        self::assertArrayHasKey('summary', $data);
        self::assertArrayHasKey('sites', $data);
        self::assertArrayHasKey('total_sites', $data['summary']);
    }

    // -------------------------------------------------------------------------
    // 2. 401 when unauthenticated
    // -------------------------------------------------------------------------

    public function testNoAuthReturns401(): void
    {
        $res = rest_do_request(new WP_REST_Request('GET', '/defyn/v1/security'));
        self::assertSame(401, $res->get_status());
    }
}
