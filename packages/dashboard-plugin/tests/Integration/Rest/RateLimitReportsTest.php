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
 * Direct unit-style coverage for the P5.3 RateLimit buckets:
 * reportsGenerate, reportsList, reportsDownload, reportsSend,
 * reportsDelete, clientEmail.
 *
 * Mirrors RateLimitPluginsUpdateTest exactly for auth setup: RequireAuth::check
 * is chained inside every method, so each request needs a real Bearer JWT.
 * We use self::factory()->user->create() for a unique userId per test so the
 * bucket starts empty. setUp() also flushes any leftover transients.
 *
 * Representative bucket under test: reportsGenerate (10/hr). All six methods
 * share the same bucket shape; the generate bucket is the canonical specimen.
 */
final class RateLimitReportsTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        // Flush any rate-limit transients that may have leaked from prior tests.
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_reportsGenerate_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_defyn_rl_reportsGenerate_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_reportsList_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_defyn_rl_reportsList_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_reportsDownload_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_defyn_rl_reportsDownload_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_reportsSend_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_defyn_rl_reportsSend_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_reportsDelete_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_defyn_rl_reportsDelete_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_clientEmail_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_defyn_rl_clientEmail_%'");
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

    private function makeRequest(string $jwt, int $siteId, string $route = '/defyn/v1/sites/1/report'): WP_REST_Request
    {
        $request = new WP_REST_Request('GET', $route);
        $request->set_header('Authorization', 'Bearer ' . $jwt);
        // URL parameters — the live route resolves these from the path; in direct
        // invocation we set them explicitly so $request['id'] returns the right value.
        $request->set_url_params(['id' => (string) $siteId]);
        return $request;
    }

    // -------------------------------------------------------------------------
    // reportsGenerate — 10/HOUR per (user, site)
    // -------------------------------------------------------------------------

    public function testReportsGenerateAllows10CallsThenRateLimits11th(): void
    {
        $ctx    = $this->authedUser();
        $siteId = 99;

        for ($i = 1; $i <= 10; $i++) {
            $result = RateLimit::reportsGenerate($this->makeRequest($ctx['jwt'], $siteId));
            self::assertTrue($result, "call {$i} should pass under the 10/hour limit; got " . var_export($result, true));
        }

        $result = RateLimit::reportsGenerate($this->makeRequest($ctx['jwt'], $siteId));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('reports.rate_limited', $result->get_error_code());
        $data = $result->get_error_data();
        self::assertSame(429, $data['status']);
    }

    public function testReportsGenerateMissingAuthShortCircuitsBeforeRateLimit(): void
    {
        $request = new WP_REST_Request('GET', '/defyn/v1/sites/99/report');
        $request->set_url_params(['id' => '99']);

        $result = RateLimit::reportsGenerate($request);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('auth.missing_token', $result->get_error_code());
        self::assertSame(401, $result->get_error_data()['status']);
    }

    // -------------------------------------------------------------------------
    // reportsList — 30/MINUTE per (user, site)
    // -------------------------------------------------------------------------

    public function testReportsListAllows30CallsThenRateLimits31st(): void
    {
        $ctx    = $this->authedUser();
        $siteId = 98;

        for ($i = 1; $i <= 30; $i++) {
            $result = RateLimit::reportsList($this->makeRequest($ctx['jwt'], $siteId));
            self::assertTrue($result, "call {$i} should pass; got " . var_export($result, true));
        }

        $result = RateLimit::reportsList($this->makeRequest($ctx['jwt'], $siteId));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('reports.rate_limited', $result->get_error_code());
        self::assertSame(429, $result->get_error_data()['status']);
    }

    // -------------------------------------------------------------------------
    // reportsDownload — 30/MINUTE per (user, site)
    // -------------------------------------------------------------------------

    public function testReportsDownloadAllows30CallsThenRateLimits31st(): void
    {
        $ctx    = $this->authedUser();
        $siteId = 97;

        for ($i = 1; $i <= 30; $i++) {
            $result = RateLimit::reportsDownload($this->makeRequest($ctx['jwt'], $siteId));
            self::assertTrue($result, "call {$i} should pass; got " . var_export($result, true));
        }

        $result = RateLimit::reportsDownload($this->makeRequest($ctx['jwt'], $siteId));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('reports.rate_limited', $result->get_error_code());
        self::assertSame(429, $result->get_error_data()['status']);
    }

    // -------------------------------------------------------------------------
    // reportsSend — 10/HOUR per (user, site)
    // -------------------------------------------------------------------------

    public function testReportsSendAllows10CallsThenRateLimits11th(): void
    {
        $ctx    = $this->authedUser();
        $siteId = 96;

        for ($i = 1; $i <= 10; $i++) {
            $result = RateLimit::reportsSend($this->makeRequest($ctx['jwt'], $siteId));
            self::assertTrue($result, "call {$i} should pass; got " . var_export($result, true));
        }

        $result = RateLimit::reportsSend($this->makeRequest($ctx['jwt'], $siteId));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('reports.rate_limited', $result->get_error_code());
        self::assertSame(429, $result->get_error_data()['status']);
    }

    // -------------------------------------------------------------------------
    // reportsDelete — 30/HOUR per (user, site)
    // -------------------------------------------------------------------------

    public function testReportsDeleteAllows30CallsThenRateLimits31st(): void
    {
        $ctx    = $this->authedUser();
        $siteId = 95;

        for ($i = 1; $i <= 30; $i++) {
            $result = RateLimit::reportsDelete($this->makeRequest($ctx['jwt'], $siteId));
            self::assertTrue($result, "call {$i} should pass; got " . var_export($result, true));
        }

        $result = RateLimit::reportsDelete($this->makeRequest($ctx['jwt'], $siteId));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('reports.rate_limited', $result->get_error_code());
        self::assertSame(429, $result->get_error_data()['status']);
    }

    // -------------------------------------------------------------------------
    // clientEmail — 10/HOUR per (user, site); 429 code = sites.rate_limited
    // -------------------------------------------------------------------------

    public function testClientEmailAllows10CallsThenRateLimits11th(): void
    {
        $ctx    = $this->authedUser();
        $siteId = 94;

        for ($i = 1; $i <= 10; $i++) {
            $result = RateLimit::clientEmail($this->makeRequest($ctx['jwt'], $siteId));
            self::assertTrue($result, "call {$i} should pass; got " . var_export($result, true));
        }

        $result = RateLimit::clientEmail($this->makeRequest($ctx['jwt'], $siteId));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('sites.rate_limited', $result->get_error_code());
        self::assertSame(429, $result->get_error_data()['status']);
    }
}
