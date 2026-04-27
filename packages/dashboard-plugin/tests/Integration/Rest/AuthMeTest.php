<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class AuthMeTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');
    }

    public function testMeWithValidAccessTokenReturns200AndUserInfo(): void
    {
        $userId = self::factory()->user->create([
            'user_email'   => 'me@defyn.test',
            'display_name' => 'Test Me',
        ]);
        $access = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);

        $request = new WP_REST_Request('GET', '/defyn/v1/auth/me');
        $request->set_header('Authorization', 'Bearer ' . $access);

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertSame($userId, $data['id']);
        self::assertSame('me@defyn.test', $data['email']);
        self::assertSame('Test Me', $data['display_name']);
    }

    public function testMeWithoutAuthHeaderReturns401(): void
    {
        $request = new WP_REST_Request('GET', '/defyn/v1/auth/me');

        $response = rest_do_request($request);

        self::assertSame(401, $response->get_status());
    }

    public function testMeWithMalformedAuthHeaderReturns401(): void
    {
        $request = new WP_REST_Request('GET', '/defyn/v1/auth/me');
        $request->set_header('Authorization', 'Basic abc123');  // wrong scheme

        $response = rest_do_request($request);

        self::assertSame(401, $response->get_status());
    }

    public function testMeWithExpiredTokenReturns401(): void
    {
        $userId = self::factory()->user->create();
        $expiredAt = time() - 7200;  // signed 2 hours ago, access TTL is 15 min
        $access = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId, $expiredAt);

        $request = new WP_REST_Request('GET', '/defyn/v1/auth/me');
        $request->set_header('Authorization', 'Bearer ' . $access);

        $response = rest_do_request($request);

        self::assertSame(401, $response->get_status());
    }

    public function testMeWithRefreshTokenInsteadOfAccessReturns401(): void
    {
        // Refresh tokens cannot be used as access tokens (typ claim mismatch).
        $userId = self::factory()->user->create();
        $refresh = (new TokenService(DEFYN_JWT_SECRET))->issueRefresh($userId);

        $request = new WP_REST_Request('GET', '/defyn/v1/auth/me');
        $request->set_header('Authorization', 'Bearer ' . $refresh);

        $response = rest_do_request($request);

        self::assertSame(401, $response->get_status());
    }
}
