<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class RateLimitTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';  // TEST-NET-3 IP
        do_action('rest_api_init');
    }

    public function tearDown(): void
    {
        // Wipe the rate-limit transient so the next test starts fresh.
        delete_transient('defyn_rl_login_203.0.113.42');
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    public function testLoginAllowsFirst5AttemptsThenRateLimits6th(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $request = new WP_REST_Request('POST', '/defyn/v1/auth/login');
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(json_encode(['email' => 'noone@defyn.test', 'password' => 'wrong']));
            $response = rest_do_request($request);
            self::assertNotSame(429, $response->get_status(), "attempt {$i} should not be rate-limited");
        }

        $request = new WP_REST_Request('POST', '/defyn/v1/auth/login');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['email' => 'noone@defyn.test', 'password' => 'wrong']));
        $response = rest_do_request($request);

        self::assertSame(429, $response->get_status(), '6th attempt should be rate-limited');
        $data = $response->get_data();
        // Spec envelope shape (rest_request_after_callbacks filter normalizes WP_Error to this shape).
        self::assertSame('rate_limited', $data['error']['code']);
    }
}
