<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Activation;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class AuthLoginTest extends WP_UnitTestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';

    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        // F9: AuthLoginController now writes to wp_defyn_activity_log on every
        // attempt. Ensure the schema exists so the write doesn't blow up with a
        // "table not found" error (dbDelta is idempotent — safe to call here).
        Activation::activate();
        // Ensure REST routes are registered for the test server.
        do_action('rest_api_init');
    }

    public function testLoginWithValidCredentialsReturns200AndAccessToken(): void
    {
        // Task 8: break-glass login is now domain-gated — only @defyn.com.au allowed.
        self::factory()->user->create([
            'user_email' => 'breakglass@defyn.com.au',
            'user_pass'  => self::PASSWORD,
        ]);

        $request = new WP_REST_Request('POST', '/defyn/v1/auth/login');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['email' => 'breakglass@defyn.com.au', 'password' => self::PASSWORD]));

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertArrayHasKey('access_token', $data);
        self::assertNotEmpty($data['access_token']);
    }

    public function testLoginWithBadPasswordReturns401(): void
    {
        self::factory()->user->create([
            'user_email' => 'login2@defyn.com.au',
            'user_pass'  => self::PASSWORD,
        ]);

        $request = new WP_REST_Request('POST', '/defyn/v1/auth/login');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['email' => 'login2@defyn.com.au', 'password' => 'wrong']));

        $response = rest_do_request($request);

        self::assertSame(401, $response->get_status());
        $data = $response->get_data();
        self::assertArrayHasKey('error', $data);
        self::assertSame('auth.invalid_credentials', $data['error']['code']);
    }

    public function testLoginWithMissingFieldsReturns400(): void
    {
        $request = new WP_REST_Request('POST', '/defyn/v1/auth/login');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['email' => 'noone@defyn.test']));  // password missing

        $response = rest_do_request($request);

        self::assertSame(400, $response->get_status());
    }

    /**
     * Task 8: break-glass /auth/login must reject accounts outside @defyn.com.au.
     */
    public function testRejectsNonDefynDomainWith403(): void
    {
        self::factory()->user->create(['user_email' => 'outsider@gmail.com', 'user_pass' => 'pw']);

        $request = new WP_REST_Request('POST', '/defyn/v1/auth/login');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['email' => 'outsider@gmail.com', 'password' => 'pw']));

        $response = rest_do_request($request);

        self::assertSame(403, $response->get_status());
        $data = $response->get_data();
        self::assertSame('auth.domain_forbidden', $data['error']['code']);
    }
}
