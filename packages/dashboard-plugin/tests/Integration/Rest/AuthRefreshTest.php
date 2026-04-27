<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\RefreshTokenStore;
use Defyn\Dashboard\Auth\TokenService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class AuthRefreshTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');
    }

    public function tearDown(): void
    {
        unset($_COOKIE['defyn_refresh']);
        parent::tearDown();
    }

    public function testRefreshWithValidCookieReturns200AndNewAccessToken(): void
    {
        $userId = self::factory()->user->create();
        $tokens = new TokenService(DEFYN_JWT_SECRET);
        $refresh = $tokens->issueRefresh($userId);
        $claims = $tokens->decode($refresh);
        (new RefreshTokenStore())->remember($userId, $claims['jti'], (int) $claims['exp']);

        $_COOKIE['defyn_refresh'] = $refresh;

        $request = new WP_REST_Request('POST', '/defyn/v1/auth/refresh');
        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertArrayHasKey('access_token', $data);
    }

    public function testRefreshRotatesJti(): void
    {
        $userId = self::factory()->user->create();
        $tokens = new TokenService(DEFYN_JWT_SECRET);
        $refresh = $tokens->issueRefresh($userId);
        $claims = $tokens->decode($refresh);
        $oldJti = $claims['jti'];
        (new RefreshTokenStore())->remember($userId, $oldJti, (int) $claims['exp']);

        $_COOKIE['defyn_refresh'] = $refresh;
        rest_do_request(new WP_REST_Request('POST', '/defyn/v1/auth/refresh'));

        // Old JTI should now be revoked
        self::assertFalse((new RefreshTokenStore())->isActive($userId, $oldJti), 'old JTI should be revoked after rotation');
    }

    public function testRefreshWithMissingCookieReturns401(): void
    {
        unset($_COOKIE['defyn_refresh']);
        $response = rest_do_request(new WP_REST_Request('POST', '/defyn/v1/auth/refresh'));

        self::assertSame(401, $response->get_status());
    }

    public function testRefreshWithRevokedJtiReturns401(): void
    {
        $userId = self::factory()->user->create();
        $tokens = new TokenService(DEFYN_JWT_SECRET);
        $refresh = $tokens->issueRefresh($userId);
        // Note: NOT calling RefreshTokenStore::remember — JTI is "revoked" (never tracked)

        $_COOKIE['defyn_refresh'] = $refresh;
        $response = rest_do_request(new WP_REST_Request('POST', '/defyn/v1/auth/refresh'));

        self::assertSame(401, $response->get_status());
    }

    public function testRefreshWithAccessTokenInCookieReturns401(): void
    {
        // Cookie contains an access token (typ=access) instead of refresh.
        $userId = self::factory()->user->create();
        $access = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);

        $_COOKIE['defyn_refresh'] = $access;
        $response = rest_do_request(new WP_REST_Request('POST', '/defyn/v1/auth/refresh'));

        self::assertSame(401, $response->get_status());
    }
}
