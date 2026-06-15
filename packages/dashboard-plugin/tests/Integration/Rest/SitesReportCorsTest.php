<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\Middleware\Cors;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P5.1 — CORS regression for the new per-site report route:
 *   - GET /defyn/v1/sites/{id}/report
 *
 * Mirrors SecurityFleetCorsTest: drives Cors::apply directly because
 * rest_pre_serve_request fires outside the WP_REST_Request lifecycle
 * in WP_UnitTestCase.
 *
 * @group integration
 */
final class SitesReportCorsTest extends AbstractSchemaTestCase
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
            'GET /sites/1/report' => ['GET', '/defyn/v1/sites/1/report'],
        ];
    }
}
