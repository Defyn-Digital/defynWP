<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Rest\Middleware\RateLimit;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 *
 * Direct unit-style coverage for the P7.1 RateLimit buckets:
 * linksScan (6/HOUR per (user, site)) and
 * linksRead (30/MINUTE per (user, site)).
 *
 * Mirrors RateLimitPerformanceTest exactly for auth setup: RequireAuth::check
 * is chained inside every method, so each request needs a real Bearer JWT.
 * We use self::factory()->user->create() for a unique userId per test so the
 * bucket starts empty. setUp() also flushes any leftover transients.
 */
final class RateLimitLinksTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        // Flush any rate-limit transients that may have leaked from prior tests.
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_linksScan_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_defyn_rl_linksScan_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_linksRead_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_defyn_rl_linksRead_%'");
        wp_cache_flush();
        do_action('rest_api_init');
    }

    /** @return array{userId:int, jwt:string} */
    private function authedUser(): array
    {
        $userId = self::factory()->user->create();
        $jwt    = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
        return ['userId' => $userId, 'jwt' => $jwt];
    }

    private function makeRequest(string $jwt, int $siteId, string $route = '/defyn/v1/sites/1/links/scan'): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', $route);
        $request->set_header('Authorization', 'Bearer ' . $jwt);
        // URL parameters — the live route resolves these from the path; in direct
        // invocation we set them explicitly so $request['id'] returns the right value.
        $request->set_url_params(['id' => (string) $siteId]);
        return $request;
    }

    // -------------------------------------------------------------------------
    // linksScan — 6/HOUR per (user, site)
    // -------------------------------------------------------------------------

    public function testLinksScanAllows6CallsThenRateLimits7th(): void
    {
        $ctx = $this->authedUser();
        $siteId = 191;
        for ($i = 1; $i <= 6; $i++) {
            $result = RateLimit::linksScan($this->makeRequest($ctx['jwt'], $siteId));
            self::assertTrue($result, "call {$i} should pass under the 6/hour limit; got " . var_export($result, true));
        }
        $result = RateLimit::linksScan($this->makeRequest($ctx['jwt'], $siteId));
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('links.rate_limited', $result->get_error_code());
        self::assertSame(429, $result->get_error_data()['status']);
    }

    // -------------------------------------------------------------------------
    // linksRead — 30/MINUTE per (user, site)
    // -------------------------------------------------------------------------

    public function testLinksReadAllows30CallsThenRateLimits31st(): void
    {
        $ctx = $this->authedUser();
        $siteId = 190;
        for ($i = 1; $i <= 30; $i++) {
            $result = RateLimit::linksRead($this->makeRequest($ctx['jwt'], $siteId, '/defyn/v1/sites/1/links'));
            self::assertTrue($result, "call {$i} should pass; got " . var_export($result, true));
        }
        $result = RateLimit::linksRead($this->makeRequest($ctx['jwt'], $siteId, '/defyn/v1/sites/1/links'));
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('links.rate_limited', $result->get_error_code());
        self::assertSame(429, $result->get_error_data()['status']);
    }

    // -------------------------------------------------------------------------
    // auth short-circuit
    // -------------------------------------------------------------------------

    public function testLinksScanMissingAuthShortCircuits(): void
    {
        $request = new WP_REST_Request('POST', '/defyn/v1/sites/191/links/scan');
        $request->set_url_params(['id' => '191']);
        $result = RateLimit::linksScan($request);
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('auth.missing_token', $result->get_error_code());
        self::assertSame(401, $result->get_error_data()['status']);
    }
}
