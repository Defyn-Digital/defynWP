<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\Middleware\Cors;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @group integration
 *
 * Regression coverage for the P2.7 GET /defyn/v1/overview/pending-plugin-updates
 * route — pins the cross-cutting wire the SPA depends on but that the controller
 * tests don't exercise:
 *
 *   1. CORS headers fire for this route (Cors middleware namespace check).
 *
 * Mirrors the P2.6 OverviewSyncAllCorsTest pattern: drive the middleware
 * directly because rest_pre_serve_request fires outside the WP_REST_Request
 * lifecycle in WP_UnitTestCase and is awkward to assert against.
 */
final class OverviewPendingPluginUpdatesCorsTest extends AbstractSchemaTestCase
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

    public function testOptionsPreflightOnPendingPluginUpdatesRouteReturnsCorsHeaders(): void
    {
        $response = new WP_REST_Response(['ok' => true], 200);
        $request  = new WP_REST_Request('GET', '/defyn/v1/overview/pending-plugin-updates');
        $server   = rest_get_server();

        $served = Cors::apply(false, $response, $request, $server);

        $headers = $response->get_headers();
        self::assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        self::assertSame(DEFYN_SPA_ORIGIN, $headers['Access-Control-Allow-Origin']);
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
        self::assertSame(
            'GET, POST, OPTIONS',
            $headers['Access-Control-Allow-Methods'],
            'OPTIONS preflight must advertise GET so the SPA can preflight the pending-plugin-updates fetch',
        );
        self::assertSame(false, $served, 'apply must return the served bool unchanged');
    }
}
