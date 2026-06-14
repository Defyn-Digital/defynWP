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
 * Regression coverage for the P3.3 POST /defyn/v1/sites/{id}/alerts/mute
 * route — pins the cross-cutting CORS wire the SPA depends on but that the
 * controller test doesn't exercise.
 *
 * Mirrors SitesCoreAllowMajorCorsTest (P2.4.1).
 */
final class SitesAlertsMuteCorsTest extends AbstractSchemaTestCase
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

    public function testOptionsPreflightOnAlertsMuteRouteReturnsCorsHeaders(): void
    {
        $response = new WP_REST_Response(['ok' => true], 200);
        $request  = new WP_REST_Request('OPTIONS', '/defyn/v1/sites/1/alerts/mute');
        $server   = rest_get_server();

        $served = Cors::apply(false, $response, $request, $server);

        $headers = $response->get_headers();
        self::assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        self::assertSame(
            DEFYN_SPA_ORIGIN,
            $headers['Access-Control-Allow-Origin'],
            'OPTIONS preflight must return the configured SPA origin'
        );
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
        self::assertSame(
            'GET, POST, OPTIONS',
            $headers['Access-Control-Allow-Methods'],
            'OPTIONS preflight must advertise POST so the SPA can preflight the POST request'
        );
        self::assertSame(false, $served, 'apply must return the served bool unchanged');
    }
}
