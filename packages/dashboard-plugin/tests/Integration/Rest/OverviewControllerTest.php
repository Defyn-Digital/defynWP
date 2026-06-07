<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * @group integration
 */
final class OverviewControllerTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        Activation::activate();
        do_action('rest_api_init');
    }

    public function tearDown(): void
    {
        // Wipe the per-user rate-limit transients so tests start fresh.
        for ($i = 1; $i <= 10; $i++) {
            delete_transient("defyn_rl_overview_{$i}");
        }
        parent::tearDown();
    }

    public function testAuthRequiredReturns401WhenNoBearerToken(): void
    {
        $request = new WP_REST_Request('GET', '/defyn/v1/overview');
        $response = rest_do_request($request);

        $this->assertSame(401, $response->get_status());
    }

    public function testHappyPath200WithFullEnvelopeShape(): void
    {
        $this->seedSite(1);

        $token = $this->token(1);

        $request = new WP_REST_Request('GET', '/defyn/v1/overview');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertArrayHasKey('pending_updates', $body);
        $this->assertArrayHasKey('sites_needing_attention', $body);
        $this->assertArrayHasKey('recent_activity', $body);
        $this->assertArrayHasKey('generated_at', $body);
    }

    public function testRateLimit429AfterThirtyFirstCall(): void
    {
        $this->seedSite(1);
        $token = $this->token(1);

        for ($i = 0; $i < 30; $i++) {
            $request = new WP_REST_Request('GET', '/defyn/v1/overview');
            $request->set_header('Authorization', 'Bearer ' . $token);
            $resp = rest_do_request($request);
            $this->assertSame(200, $resp->get_status(), "call #" . ($i + 1) . " should be 200");
        }

        $request = new WP_REST_Request('GET', '/defyn/v1/overview');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $resp = rest_do_request($request);
        $this->assertSame(429, $resp->get_status());
        $this->assertSame('overview.rate_limited', $resp->get_data()['error']['code']);
    }

    public function testOwnershipScopingExcludesOtherUsersSites(): void
    {
        $this->seedSite(2);
        $token = $this->token(1);

        $request = new WP_REST_Request('GET', '/defyn/v1/overview');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertSame(0, $body['pending_updates']['plugins']);
        $this->assertSame([], $body['sites_needing_attention']);
    }

    public function testNoStoreCacheHeader(): void
    {
        $token = $this->token(1);
        $request = new WP_REST_Request('GET', '/defyn/v1/overview');
        $request->set_header('Authorization', 'Bearer ' . $token);

        $response = rest_do_request($request);
        $response = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);

        $this->assertStringContainsString(
            'no-store',
            $response->get_headers()['Cache-Control'] ?? ''
        );
    }

    private function seedSite(int $userId): int
    {
        return (new SitesRepository())->insertPending(
            $userId,
            'https://ex' . microtime(true) . '.com',
            'Example',
            'pub-key',
            'enc-key'
        );
    }

    private function token(int $userId): string
    {
        return (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
    }
}
