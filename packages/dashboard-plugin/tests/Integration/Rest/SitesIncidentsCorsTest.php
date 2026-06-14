<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\Middleware\Cors;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P3.1 — CORS regression for GET /defyn/v1/sites/{id}/incidents.
 *
 * Mirrors JobsRoutesCorsTest: drives Cors::apply directly because
 * rest_pre_serve_request fires outside the WP_REST_Request lifecycle in
 * WP_UnitTestCase.
 *
 * @group integration
 */
final class SitesIncidentsCorsTest extends AbstractSchemaTestCase
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

    public function testSitesIncidentsRouteReturnsCorsHeaders(): void
    {
        $response = new WP_REST_Response(['ok' => true], 200);
        $request  = new WP_REST_Request('GET', '/defyn/v1/sites/42/incidents');
        $served   = Cors::apply(false, $response, $request, rest_get_server());

        $headers = $response->get_headers();
        self::assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        self::assertSame(DEFYN_SPA_ORIGIN, $headers['Access-Control-Allow-Origin']);
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
        self::assertStringContainsString('GET', $headers['Access-Control-Allow-Methods']);
        self::assertSame(false, $served, 'apply must return the served bool unchanged');
    }
}
