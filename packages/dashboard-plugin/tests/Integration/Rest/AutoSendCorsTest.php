<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Rest\Middleware\Cors;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P5.4 Task 8 — CORS + route-resolution regression for the auto-send route:
 *   - POST /defyn/v1/sites/{id}/auto-send
 *
 * Mirrors AnalyticsCorsTest: drives Cors::apply directly because
 * rest_pre_serve_request fires outside the WP_REST_Request lifecycle
 * in WP_UnitTestCase. Adds a route-resolution assertion (authed dispatch
 * to a non-owned site returns the controller's 404 sites.not_found, NOT
 * rest_no_route) to prove the route actually registers.
 *
 * @group integration
 */
final class AutoSendCorsTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_SPA_ORIGIN')) {
            define('DEFYN_SPA_ORIGIN', 'http://localhost:5173');
        }
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');
    }

    /** @dataProvider routes */
    public function testCorsHeaders(string $method, string $route): void
    {
        $response = new WP_REST_Response(['ok' => true], 200);
        $request  = new WP_REST_Request($method, $route);
        $served   = Cors::apply(false, $response, $request, rest_get_server());

        $headers = $response->get_headers();
        self::assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        self::assertSame(DEFYN_SPA_ORIGIN, $headers['Access-Control-Allow-Origin']);
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
        self::assertSame(false, $served);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function routes(): array
    {
        return [
            'POST /sites/1/auto-send' => ['POST', '/defyn/v1/sites/1/auto-send'],
        ];
    }

    // -------------------------------------------------------------------------
    // Route-resolution — an authed dispatch to a non-owned site resolves to the
    // controller's 404 sites.not_found, NOT WP's rest_no_route. Proves the new
    // route actually registered.
    // -------------------------------------------------------------------------

    /** @dataProvider resolutionRoutes */
    public function testRouteResolvesToControllerNotFound(string $method, string $route): void
    {
        $userId = self::factory()->user->create();
        $token  = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);

        $request = new WP_REST_Request($method, $route);
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);

        self::assertSame(404, $response->get_status());
        self::assertSame('sites.not_found', $response->get_data()['error']['code']);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function resolutionRoutes(): array
    {
        return [
            'POST /sites/999999/auto-send' => ['POST', '/defyn/v1/sites/999999/auto-send'],
        ];
    }
}
