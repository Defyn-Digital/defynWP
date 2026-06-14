<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Auth\TokenService;
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
        // F9: AuthLoginController writes to wp_defyn_activity_log on every
        // attempt, so the schema must exist (dbDelta is idempotent).
        Activation::activate();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';  // TEST-NET-3 IP
        do_action('rest_api_init');
    }

    public function tearDown(): void
    {
        // Wipe the rate-limit transients so the next test starts fresh.
        delete_transient('defyn_rl_login_203.0.113.42');
        // Wipe monitoring bucket for user 1 (used in the monitoring rate-limit test).
        delete_transient('defyn_rl_monitoring_1');
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
        self::assertSame('auth.rate_limited', $data['error']['code']);
    }

    /**
     * RateLimit::monitoring must allow 30 calls then trip on the 31st.
     *
     * Mirrors OverviewControllerTest::testRateLimit429AfterThirtyFirstCall but
     * exercises the method directly (not via rest_do_request) to keep the test
     * fast and isolated. RequireAuth::check reads the Authorization header, so
     * we supply a real JWT for user 1 instead of relying on a pre-set param.
     */
    public function testMonitoringRateLimitTripsAfterThirtyFirstCall(): void
    {
        $token = (new TokenService(DEFYN_JWT_SECRET))->issueAccess(1);

        for ($i = 0; $i < 30; $i++) {
            $request = new WP_REST_Request('GET', '/defyn/v1/monitoring');
            $request->set_header('Authorization', 'Bearer ' . $token);
            $result = \Defyn\Dashboard\Rest\Middleware\RateLimit::monitoring($request);
            self::assertSame(true, $result, "call #" . ($i + 1) . " should be allowed");
        }

        $request = new WP_REST_Request('GET', '/defyn/v1/monitoring');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $result = \Defyn\Dashboard\Rest\Middleware\RateLimit::monitoring($request);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(429, $result->get_error_data()['status']);
        self::assertSame('monitoring.rate_limited', $result->get_error_code());
    }
}
