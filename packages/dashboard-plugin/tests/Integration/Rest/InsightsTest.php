<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Rest\Middleware\RateLimit;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_Error;
use WP_REST_Request;

/**
 * P6.3 — GET /defyn/v1/insights
 *
 * Mirrors SecurityFleetTest: direct payload (no {data} envelope), 30/min
 * per-user bucket, 401 without auth. The combined Performance + Analytics
 * rollup carries three top-level keys (performance, analytics, generated_at),
 * each section with summary + sites.
 *
 * @group integration
 */
final class InsightsTest extends AbstractSchemaTestCase
{
    private int $userId;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');

        $this->userId = self::factory()->user->create();
        $this->token  = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($this->userId);

        // Isolate the per-user rate-limit bucket between tests.
        delete_transient(sprintf('defyn_rl_insights_%d', $this->userId));
    }

    protected function tearDown(): void
    {
        delete_transient(sprintf('defyn_rl_insights_%d', $this->userId));
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // 1. 200 + direct payload (performance + analytics + generated_at present,
    //    each section carrying summary + sites)
    // -------------------------------------------------------------------------

    public function testAuthenticatedGetReturns200DirectPayload(): void
    {
        $req = new WP_REST_Request('GET', '/defyn/v1/insights');
        $req->set_header('Authorization', 'Bearer ' . $this->token);
        $res = rest_do_request($req);

        self::assertSame(200, $res->get_status());

        $data = $res->get_data();
        self::assertArrayHasKey('performance', $data);
        self::assertArrayHasKey('analytics', $data);
        self::assertArrayHasKey('generated_at', $data);

        self::assertArrayHasKey('summary', $data['performance']);
        self::assertArrayHasKey('sites', $data['performance']);
        self::assertArrayHasKey('summary', $data['analytics']);
        self::assertArrayHasKey('sites', $data['analytics']);
    }

    // -------------------------------------------------------------------------
    // 2. 401 when unauthenticated
    // -------------------------------------------------------------------------

    public function testNoAuthReturns401(): void
    {
        $res = rest_do_request(new WP_REST_Request('GET', '/defyn/v1/insights'));
        self::assertSame(401, $res->get_status());
    }

    // -------------------------------------------------------------------------
    // 3. Route resolves — the registered GET does NOT 404 with rest_no_route
    // -------------------------------------------------------------------------

    public function testRouteIsRegistered(): void
    {
        $req = new WP_REST_Request('GET', '/defyn/v1/insights');
        $req->set_header('Authorization', 'Bearer ' . $this->token);
        $res = rest_do_request($req);

        self::assertNotSame('rest_no_route', $res->get_data()['code'] ?? null);
        self::assertSame(200, $res->get_status());
    }

    // -------------------------------------------------------------------------
    // 4. 429 with code insights.rate_limited after INSIGHTS_LIMIT (30) GETs.
    //    Drives the permission callback directly (mirrors RateLimitAnalyticsTest)
    //    so the WP_Error code/status are asserted without the {error} REST wrap.
    // -------------------------------------------------------------------------

    public function testRateLimitReturns429AfterLimit(): void
    {
        for ($i = 1; $i <= RateLimit::INSIGHTS_LIMIT; $i++) {
            self::assertTrue(RateLimit::insights($this->req()), "request #$i should pass");
        }

        // The 31st request trips the bucket.
        $res = RateLimit::insights($this->req());

        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('insights.rate_limited', $res->get_error_code());
        self::assertSame(429, $res->get_error_data()['status']);
    }

    private function req(): WP_REST_Request
    {
        $req = new WP_REST_Request('GET', '/defyn/v1/insights');
        $req->set_header('Authorization', 'Bearer ' . $this->token);
        return $req;
    }
}
