<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Rest\Middleware\RateLimit;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

final class RateLimitAnalyticsTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        global $wpdb;
        foreach (['analyticsRead','analyticsRefresh','ga4Property'] as $b) {
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_{$b}_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_defyn_rl_{$b}_%'");
        }
        wp_cache_flush();
        do_action('rest_api_init');
    }

    private function req(string $jwt, int $siteId): WP_REST_Request
    {
        $r = new WP_REST_Request('GET', '/defyn/v1/sites/1/analytics');
        $r->set_header('Authorization', 'Bearer ' . $jwt);
        $r->set_url_params(['id' => (string) $siteId]);
        return $r;
    }

    public function testAnalyticsReadAllows30Then429(): void
    {
        $uid = self::factory()->user->create();
        $jwt = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($uid);
        for ($i = 1; $i <= 30; $i++) {
            self::assertTrue(RateLimit::analyticsRead($this->req($jwt, 91)));
        }
        $res = RateLimit::analyticsRead($this->req($jwt, 91));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('analytics.rate_limited', $res->get_error_code());
        self::assertSame(429, $res->get_error_data()['status']);
    }

    public function testAnalyticsRefreshAllows6Then429(): void
    {
        $uid = self::factory()->user->create();
        $jwt = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($uid);
        for ($i = 1; $i <= 6; $i++) {
            self::assertTrue(RateLimit::analyticsRefresh($this->req($jwt, 92)));
        }
        $res = RateLimit::analyticsRefresh($this->req($jwt, 92));
        self::assertSame('analytics.rate_limited', $res->get_error_code());
    }

    public function testGa4PropertyAllows10Then429(): void
    {
        $uid = self::factory()->user->create();
        $jwt = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($uid);
        for ($i = 1; $i <= 10; $i++) {
            self::assertTrue(RateLimit::ga4Property($this->req($jwt, 93)));
        }
        $res = RateLimit::ga4Property($this->req($jwt, 93));
        self::assertSame('sites.rate_limited', $res->get_error_code());
    }

    public function testAnalyticsReadMissingAuth401(): void
    {
        $r = new WP_REST_Request('GET', '/defyn/v1/sites/91/analytics');
        $r->set_url_params(['id' => '91']);
        $res = RateLimit::analyticsRead($r);
        self::assertSame('auth.missing_token', $res->get_error_code());
        self::assertSame(401, $res->get_error_data()['status']);
    }
}
