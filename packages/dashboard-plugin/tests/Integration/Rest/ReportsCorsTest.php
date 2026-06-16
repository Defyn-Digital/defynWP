<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Rest\Middleware\Cors;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P5.3 Task 17 — CORS + route-resolution regression for the report-queue routes:
 *   - GET    /defyn/v1/sites/{id}/reports
 *   - POST   /defyn/v1/sites/{id}/reports
 *   - GET    /defyn/v1/sites/{id}/reports/{rid}/download
 *   - POST   /defyn/v1/sites/{id}/reports/{rid}/send
 *   - DELETE /defyn/v1/sites/{id}/reports/{rid}
 *   - POST   /defyn/v1/sites/{id}/client-email
 *
 * Mirrors SitesReportPdfCorsTest: drives Cors::apply directly because
 * rest_pre_serve_request fires outside the WP_REST_Request lifecycle
 * in WP_UnitTestCase. Adds a route-resolution assertion (authed dispatch
 * to a non-owned site returns the controller's 404 sites.not_found, NOT
 * rest_no_route) to prove the routes actually register.
 *
 * @group integration
 */
final class ReportsCorsTest extends AbstractSchemaTestCase
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
            'GET /sites/1/reports'            => ['GET', '/defyn/v1/sites/1/reports'],
            'POST /sites/1/reports'           => ['POST', '/defyn/v1/sites/1/reports'],
            'GET /sites/1/reports/1/download' => ['GET', '/defyn/v1/sites/1/reports/1/download'],
            'POST /sites/1/reports/1/send'    => ['POST', '/defyn/v1/sites/1/reports/1/send'],
            'DELETE /sites/1/reports/1'       => ['DELETE', '/defyn/v1/sites/1/reports/1'],
            'POST /sites/1/client-email'      => ['POST', '/defyn/v1/sites/1/client-email'],
        ];
    }

    // -------------------------------------------------------------------------
    // Route-resolution — an authed dispatch to a non-owned site resolves to the
    // controller's 404 sites.not_found, NOT WP's rest_no_route. Proves every new
    // route actually registered (and that DELETE /reports/{rid} doesn't collide
    // with GET /reports/{rid}/download or the /reports list path).
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
            'GET /sites/999999/reports'            => ['GET', '/defyn/v1/sites/999999/reports'],
            'POST /sites/999999/reports'           => ['POST', '/defyn/v1/sites/999999/reports'],
            'GET /sites/999999/reports/1/download' => ['GET', '/defyn/v1/sites/999999/reports/1/download'],
            'POST /sites/999999/reports/1/send'    => ['POST', '/defyn/v1/sites/999999/reports/1/send'],
            'DELETE /sites/999999/reports/1'       => ['DELETE', '/defyn/v1/sites/999999/reports/1'],
            'POST /sites/999999/client-email'      => ['POST', '/defyn/v1/sites/999999/client-email'],
        ];
    }
}
