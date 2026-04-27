<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\Middleware\Cors;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class CorsTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_SPA_ORIGIN')) {
            define('DEFYN_SPA_ORIGIN', 'http://localhost:5173');
        }
    }

    public function testApplyAddsAccessControlAllowOriginHeader(): void
    {
        // We test the middleware directly because rest_pre_serve_request fires
        // outside the WP_REST_Request lifecycle and is awkward to assert against.
        $response = new WP_REST_Response(['ok' => true], 200);
        $request = new WP_REST_Request('GET', '/defyn/v1/auth/me');
        $server = rest_get_server();

        $served = Cors::apply(false, $response, $request, $server);

        $headers = $response->get_headers();
        self::assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        self::assertSame(DEFYN_SPA_ORIGIN, $headers['Access-Control-Allow-Origin']);
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
        self::assertSame(false, $served, 'apply must return the served bool unchanged (false)');
    }

    public function testApplyDoesNotAddHeadersForNonDefynRoutes(): void
    {
        $response = new WP_REST_Response(['ok' => true], 200);
        $request = new WP_REST_Request('GET', '/wp/v2/posts');
        $server = rest_get_server();

        Cors::apply(false, $response, $request, $server);

        $headers = $response->get_headers();
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $headers, 'CORS should only apply to defyn/v1/*');
    }
}
