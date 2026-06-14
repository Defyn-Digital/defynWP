<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\Middleware\Cors;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P3.3 — CORS coverage for GET /defyn/v1/settings and POST /defyn/v1/settings/slack-webhook.
 *
 * Mirrors MonitoringCorsTest: drives Cors::apply() directly because
 * rest_pre_serve_request fires outside the WP_REST_Request lifecycle
 * in WP_UnitTestCase and is awkward to assert against.
 *
 * @group integration
 */
final class SettingsCorsTest extends AbstractSchemaTestCase
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

    public function testCorsHeadersOnSettingsGetRoute(): void
    {
        $response = new WP_REST_Response(['ok' => true], 200);
        $request  = new WP_REST_Request('GET', '/defyn/v1/settings');
        $server   = rest_get_server();

        $served = Cors::apply(false, $response, $request, $server);

        $headers = $response->get_headers();
        self::assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        self::assertSame(DEFYN_SPA_ORIGIN, $headers['Access-Control-Allow-Origin']);
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
        self::assertSame(false, $served);
    }

    public function testCorsHeadersOnSettingsSlackWebhookPostRoute(): void
    {
        $response = new WP_REST_Response(['ok' => true], 200);
        $request  = new WP_REST_Request('POST', '/defyn/v1/settings/slack-webhook');
        $server   = rest_get_server();

        $served = Cors::apply(false, $response, $request, $server);

        $headers = $response->get_headers();
        self::assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        self::assertSame(DEFYN_SPA_ORIGIN, $headers['Access-Control-Allow-Origin']);
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
        self::assertSame(false, $served);
    }

    public function testNonDefynRouteDoesNotGetCorsHeaders(): void
    {
        $response = new WP_REST_Response(['ok' => true], 200);
        $request  = new WP_REST_Request('GET', '/wp/v2/posts');
        $server   = rest_get_server();

        Cors::apply(false, $response, $request, $server);

        $headers = $response->get_headers();
        self::assertArrayNotHasKey('Access-Control-Allow-Origin', $headers);
    }
}
