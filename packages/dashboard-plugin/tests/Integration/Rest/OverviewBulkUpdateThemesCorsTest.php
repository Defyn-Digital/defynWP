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
 * Regression coverage for the P2.8 POST /defyn/v1/overview/bulk-update-themes
 * route — pins the cross-cutting wire the SPA depends on but that the controller
 * tests don't exercise:
 *
 *   1. CORS headers fire for this route (Cors middleware namespace check).
 *
 * Mirrors the P2.7 OverviewBulkUpdatePluginsCorsTest pattern: drive the
 * middleware directly because rest_pre_serve_request fires outside the
 * WP_REST_Request lifecycle in WP_UnitTestCase and is awkward to assert
 * against.
 */
final class OverviewBulkUpdateThemesCorsTest extends AbstractSchemaTestCase
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

    public function testOptionsPreflightOnBulkUpdateThemesRouteReturnsCorsHeaders(): void
    {
        $response = new WP_REST_Response(['ok' => true], 200);
        $request  = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $server   = rest_get_server();

        $served = Cors::apply(false, $response, $request, $server);

        $headers = $response->get_headers();
        self::assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        self::assertSame(DEFYN_SPA_ORIGIN, $headers['Access-Control-Allow-Origin']);
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
        self::assertSame(
            'GET, POST, OPTIONS',
            $headers['Access-Control-Allow-Methods'],
            'OPTIONS preflight must advertise POST so the SPA can preflight the bulk-update-themes fan-out',
        );
        self::assertSame(false, $served, 'apply must return the served bool unchanged');
    }
}
