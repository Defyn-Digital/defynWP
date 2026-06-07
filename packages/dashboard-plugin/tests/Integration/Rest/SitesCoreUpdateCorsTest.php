<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\Middleware\Cors;
use Defyn\Dashboard\Rest\RestRouter;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * @group integration
 *
 * Regression coverage for the P2.4 POST /defyn/v1/sites/{id}/core/refresh
 * and POST /defyn/v1/sites/{id}/core/update routes — pins the two cross-cutting
 * wires the SPA depends on but that the controller tests don't exercise:
 *
 *   1. CORS headers fire for these routes (Cors middleware namespace check).
 *   2. Unauthenticated POST returns the spec'd {error:{code,message}} envelope,
 *      not WP's native WP_Error shape — the same normalize_error_envelope filter
 *      must cover both core routes.
 *
 * Mirrors P2.2's SitesPluginsUpdateCorsTest pattern: drive the middleware directly
 * because rest_pre_serve_request fires outside the WP_REST_Request lifecycle in
 * WP_UnitTestCase and is awkward to assert against.
 */
final class SitesCoreUpdateCorsTest extends WP_UnitTestCase
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
        // RestRouter::register() is hooked on rest_api_init in Plugin::boot().
        // In phpunit context the action only fires the first time rest_get_server()
        // resolves, so do_action() makes the unauth-envelope test deterministic.
        do_action('rest_api_init');
    }

    public function testCorsHeadersApplyForRefreshRoute(): void
    {
        $response = new WP_REST_Response(['ok' => true], 200);
        $request  = new WP_REST_Request('POST', '/defyn/v1/sites/1/core/refresh');
        $server   = rest_get_server();

        $served = Cors::apply(false, $response, $request, $server);

        $headers = $response->get_headers();
        self::assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        self::assertSame(DEFYN_SPA_ORIGIN, $headers['Access-Control-Allow-Origin']);
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
        self::assertSame(
            'GET, POST, OPTIONS',
            $headers['Access-Control-Allow-Methods'],
            'OPTIONS preflight must be advertised so the SPA can preflight the POST',
        );
        self::assertSame(false, $served, 'apply must return the served bool unchanged');
    }

    public function testCorsHeadersApplyForUpdateRoute(): void
    {
        $response = new WP_REST_Response(['ok' => true], 200);
        $request  = new WP_REST_Request('POST', '/defyn/v1/sites/1/core/update');
        $server   = rest_get_server();

        $served = Cors::apply(false, $response, $request, $server);

        $headers = $response->get_headers();
        self::assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        self::assertSame(DEFYN_SPA_ORIGIN, $headers['Access-Control-Allow-Origin']);
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
        self::assertSame(
            'GET, POST, OPTIONS',
            $headers['Access-Control-Allow-Methods'],
            'OPTIONS preflight must be advertised so the SPA can preflight the POST',
        );
        self::assertSame(false, $served, 'apply must return the served bool unchanged');
    }

    public function testUnauthenticatedPostReturnsEnvelopeShape(): void
    {
        $request = new WP_REST_Request('POST', '/defyn/v1/sites/1/core/update');

        $response = rest_do_request($request);

        self::assertSame(401, $response->get_status());

        $body = $response->get_data();
        self::assertIsArray($body);
        self::assertArrayHasKey('error', $body, 'unauth response must carry the {error:{...}} envelope, not WP_Error shape');
        self::assertArrayHasKey('code', $body['error']);
        self::assertArrayHasKey('message', $body['error']);
        self::assertSame('auth.missing_token', $body['error']['code']);
    }
}
