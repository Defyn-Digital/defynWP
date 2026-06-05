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
 * Direct unit-style coverage for RateLimit::pluginsUpdate. We bypass the REST
 * router and invoke the permission callback in isolation — the end-to-end
 * route wiring is exercised by SitesPluginsUpdateTest in a later task.
 *
 * RequireAuth::check is chained inside pluginsUpdate, so every request needs a
 * real Bearer JWT (mirroring SitesPluginsRefreshTest's auth setup). We can't
 * just pre-set `_authenticated_user_id` on the request — RequireAuth would
 * reject the call with auth.missing_token before reaching the rate-limit
 * logic, and on success it overwrites the param from decoded JWT claims.
 */
final class RateLimitPluginsUpdateTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        // Wipe any rate-limit transients leaked from prior tests. WP_UnitTestCase
        // wraps each test in a DB transaction, but transient options can persist
        // through wp_cache_set even after rollback — explicit delete is safer.
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_pluginsUpdate_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_defyn_rl_pluginsUpdate_%'");
        wp_cache_flush();
    }

    /** @return array{userId:int, jwt:string} */
    private function authedUser(): array
    {
        $userId = self::factory()->user->create();
        $jwt    = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
        return ['userId' => $userId, 'jwt' => $jwt];
    }

    private function makeRequest(string $jwt, int $siteId, string $slug): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', "/defyn/v1/sites/{$siteId}/plugins/{$slug}/update");
        $request->set_header('Authorization', 'Bearer ' . $jwt);
        // URL parameters — the live route would resolve these from the path; in
        // direct invocation we set them explicitly so $request['id'] / ['slug']
        // (which is what pluginsUpdate reads) return the right values.
        $request->set_url_params(['id' => (string) $siteId, 'slug' => $slug]);
        return $request;
    }

    public function testSeventhRequestInOneHourReturns429(): void
    {
        $ctx    = $this->authedUser();
        $siteId = 7;
        $slug   = 'akismet';

        for ($i = 1; $i <= 6; $i++) {
            $result = RateLimit::pluginsUpdate($this->makeRequest($ctx['jwt'], $siteId, $slug));
            self::assertTrue($result, "call {$i} should pass under the 6/hour limit; got " . var_export($result, true));
        }

        $result = RateLimit::pluginsUpdate($this->makeRequest($ctx['jwt'], $siteId, $slug));
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('plugins.rate_limited', $result->get_error_code());
        $data = $result->get_error_data();
        self::assertSame(429, $data['status']);
    }

    public function testDifferentSlugsHaveSeparateBuckets(): void
    {
        $ctx    = $this->authedUser();
        $siteId = 7;

        // Burn the bucket for akismet.
        for ($i = 1; $i <= 6; $i++) {
            RateLimit::pluginsUpdate($this->makeRequest($ctx['jwt'], $siteId, 'akismet'));
        }

        // 7th akismet call → rate-limited.
        $akismetSeventh = RateLimit::pluginsUpdate($this->makeRequest($ctx['jwt'], $siteId, 'akismet'));
        self::assertInstanceOf(WP_Error::class, $akismetSeventh);
        self::assertSame('plugins.rate_limited', $akismetSeventh->get_error_code());

        // First jetpack call → separate bucket, still passes.
        $jetpackFirst = RateLimit::pluginsUpdate($this->makeRequest($ctx['jwt'], $siteId, 'jetpack'));
        self::assertTrue($jetpackFirst, 'jetpack bucket must be independent of akismet bucket');
    }

    public function testMissingAuthShortCircuitsBeforeRateLimit(): void
    {
        // No Authorization header → RequireAuth::check returns auth.missing_token
        // (401), and pluginsUpdate must surface that error without touching the
        // rate-limit transient. Locks in the chain order documented in pluginsRefresh.
        $request = new WP_REST_Request('POST', '/defyn/v1/sites/7/plugins/akismet/update');
        $request->set_url_params(['id' => '7', 'slug' => 'akismet']);

        $result = RateLimit::pluginsUpdate($request);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('auth.missing_token', $result->get_error_code());
        self::assertSame(401, $result->get_error_data()['status']);
    }
}
