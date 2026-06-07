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
 * Regression coverage for the P2.4.1 POST /defyn/v1/sites/{id}/core/allow-major
 * route — pins the cross-cutting wire the SPA depends on but that the controller
 * test doesn't exercise:
 *
 *   1. CORS headers fire for this route (Cors middleware namespace check).
 *
 * Mirrors P2.3's pattern: drive the middleware directly because rest_pre_serve_request
 * fires outside the WP_REST_Request lifecycle in WP_UnitTestCase and is awkward to
 * assert against.
 */
final class SitesCoreAllowMajorCorsTest extends AbstractSchemaTestCase
{
    public function testOptionsPreflightOnCoreAllowMajorRouteReturnsCorsHeaders(): void
    {
        $siteId = $this->seedSite();

        $response = new WP_REST_Response(['ok' => true], 200);
        $request  = new WP_REST_Request('OPTIONS', '/defyn/v1/sites/' . $siteId . '/core/allow-major');
        $server   = rest_get_server();

        // Drive the Cors middleware directly to verify CORS headers are applied
        // to the new allow-major route.
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

    private function seedSite(): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => 1,
            'url'        => 'https://example.com',
            'label'      => 'Example',
            'status'     => 'connected',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }
}
