<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Rest\Middleware\RateLimit;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

final class RateLimitAutoSendTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_autoSend_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_defyn_rl_autoSend_%'");
        wp_cache_flush();
        do_action('rest_api_init');
    }

    private function req(string $jwt, int $siteId): WP_REST_Request
    {
        $r = new WP_REST_Request('POST', '/defyn/v1/sites/1/auto-send');
        $r->set_header('Authorization', 'Bearer ' . $jwt);
        $r->set_url_params(['id' => (string) $siteId]);
        return $r;
    }

    public function testAllows10Then429(): void
    {
        $uid = self::factory()->user->create();
        $jwt = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($uid);
        for ($i = 1; $i <= 10; $i++) {
            self::assertTrue(RateLimit::autoSend($this->req($jwt, 80)));
        }
        $res = RateLimit::autoSend($this->req($jwt, 80));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('sites.rate_limited', $res->get_error_code());
        self::assertSame(429, $res->get_error_data()['status']);
    }

    public function testMissingAuth401(): void
    {
        $r = new WP_REST_Request('POST', '/defyn/v1/sites/80/auto-send');
        $r->set_url_params(['id' => '80']);
        $res = RateLimit::autoSend($r);
        self::assertSame('auth.missing_token', $res->get_error_code());
    }
}
