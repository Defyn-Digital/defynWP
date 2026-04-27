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
final class AuthLogoutTest extends WP_UnitTestCase
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

    public function testLogoutRevokesJtiAndReturns204(): void
    {
        $userId = self::factory()->user->create();
        $tokens = new TokenService(DEFYN_JWT_SECRET);
        $refresh = $tokens->issueRefresh($userId);
        $claims = $tokens->decode($refresh);
        $store = new RefreshTokenStore();
        $store->remember($userId, $claims['jti'], (int) $claims['exp']);

        $_COOKIE['defyn_refresh'] = $refresh;
        $response = rest_do_request(new WP_REST_Request('POST', '/defyn/v1/auth/logout'));

        self::assertSame(204, $response->get_status());
        self::assertFalse($store->isActive($userId, $claims['jti']), 'JTI should be revoked after logout');
    }

    public function testLogoutWithoutCookieStillReturns204(): void
    {
        unset($_COOKIE['defyn_refresh']);
        $response = rest_do_request(new WP_REST_Request('POST', '/defyn/v1/auth/logout'));

        // Idempotent: logging out without a session is a no-op success.
        self::assertSame(204, $response->get_status());
    }

    public function testLogoutWithMalformedCookieStillReturns204(): void
    {
        $_COOKIE['defyn_refresh'] = 'not.a.jwt';
        $response = rest_do_request(new WP_REST_Request('POST', '/defyn/v1/auth/logout'));

        self::assertSame(204, $response->get_status());
    }
}
