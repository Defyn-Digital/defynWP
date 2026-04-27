<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

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
        // Ensure REST routes are registered for the test server.
        do_action('rest_api_init');
    }

    public function testLoginWithValidCredentialsReturns200AndAccessToken(): void
    {
        self::factory()->user->create([
            'user_email' => 'login@defyn.test',
            'user_pass'  => self::PASSWORD,
        ]);

        $request = new WP_REST_Request('POST', '/defyn/v1/auth/login');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['email' => 'login@defyn.test', 'password' => self::PASSWORD]));

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertArrayHasKey('access_token', $data);
        self::assertNotEmpty($data['access_token']);
    }

    public function testLoginWithBadPasswordReturns401(): void
    {
        self::factory()->user->create([
            'user_email' => 'login2@defyn.test',
            'user_pass'  => self::PASSWORD,
        ]);

        $request = new WP_REST_Request('POST', '/defyn/v1/auth/login');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['email' => 'login2@defyn.test', 'password' => 'wrong']));

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
}
