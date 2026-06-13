<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\Middleware\Cors;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.9 — CORS regression for the 5 new /jobs* routes.
 *
 * Mirrors OverviewBulkUpdateThemesCorsTest: drives Cors::apply directly
 * because rest_pre_serve_request fires outside the WP_REST_Request
 * lifecycle in WP_UnitTestCase.
 *
 * @group integration
 */
final class JobsRoutesCorsTest extends AbstractSchemaTestCase
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

    /** @return array{0: WP_REST_Response, 1: bool} */
    private function applyCors(string $method, string $route): array
    {
        $response = new WP_REST_Response(['ok' => true], 200);
        $request  = new WP_REST_Request($method, $route);
        $served   = Cors::apply(false, $response, $request, rest_get_server());
        return [$response, $served];
    }

    private function assertCorsHeaders(WP_REST_Response $response, bool $served, string $expectedMethod): void
    {
        $headers = $response->get_headers();
        self::assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        self::assertSame(DEFYN_SPA_ORIGIN, $headers['Access-Control-Allow-Origin']);
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
        self::assertStringContainsString($expectedMethod, $headers['Access-Control-Allow-Methods']);
        self::assertSame(false, $served, 'apply must return the served bool unchanged');
    }

    public function testJobsListRouteReturnsCorsHeaders(): void
    {
        [$response, $served] = $this->applyCors('GET', '/defyn/v1/jobs');
        $this->assertCorsHeaders($response, $served, 'GET');
    }

    public function testJobsDetailRouteReturnsCorsHeaders(): void
    {
        [$response, $served] = $this->applyCors('GET', '/defyn/v1/jobs/42');
        $this->assertCorsHeaders($response, $served, 'GET');
    }

    public function testJobsCancelRouteReturnsCorsHeaders(): void
    {
        [$response, $served] = $this->applyCors('POST', '/defyn/v1/jobs/42/cancel');
        $this->assertCorsHeaders($response, $served, 'POST');
    }

    public function testJobsRetryItemRouteReturnsCorsHeaders(): void
    {
        [$response, $served] = $this->applyCors('POST', '/defyn/v1/jobs/42/items/201/retry');
        $this->assertCorsHeaders($response, $served, 'POST');
    }

    public function testJobsRetryFailedRouteReturnsCorsHeaders(): void
    {
        [$response, $served] = $this->applyCors('POST', '/defyn/v1/jobs/42/retry-failed');
        $this->assertCorsHeaders($response, $served, 'POST');
    }
}
