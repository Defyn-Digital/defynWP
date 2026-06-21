<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Activation;
use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * P7.1 Task 5 — Cache-Control: no-store regression on /links/scan.
 *
 * Pins two things at once:
 *   1. RestRouter::register() actually wires the new route (no test-only
 *      registration in setUp — the route must exist after a vanilla
 *      `do_action('rest_api_init')` that fires the router).
 *   2. The success response flows through the existing applyNoCacheHeaders
 *      filter and ships Cache-Control: no-store + no-cache + private,
 *      Pragma: no-cache, Expires: 0.
 *
 * Note: rest_do_request() skips the rest_post_dispatch filter pipeline that
 * applyNoCacheHeaders is hooked on; we invoke the filter manually below.
 * (P2.1 Task 4 lesson, fix 2770cd0.)
 *
 * Spec: docs/superpowers/specs/P7.1-broken-link-monitoring
 *
 * @group integration
 */
final class LinksScanCacheHeadersTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();

        (new ConnectorState())->reset();
        Activation::activate();
        do_action('rest_api_init');

        $keypair = sodium_crypto_sign_keypair();
        $this->privateKeyBase64 = base64_encode(sodium_crypto_sign_secretkey($keypair));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'connected_at'         => gmdate('c'),
        ]);

        // Stub all outbound HTTP so the link scanner never hits the network.
        add_filter('pre_http_request', static fn () => [
            'response' => ['code' => 200],
            'body'     => '',
        ], 10, 0);
    }

    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        parent::tearDown();
    }

    public function testSuccessResponseGetsNoStoreHeaders(): void
    {
        $request  = $this->makeSignedRequest();
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());

        // rest_do_request() skips rest_post_dispatch — invoke the filter
        // ourselves to exercise the same code path production HTTP traffic
        // hits via serve_request(). (P2.1 Task 4 lesson, fix 2770cd0.)
        $filtered = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);

        $this->assertInstanceOf(WP_REST_Response::class, $filtered);

        $headers      = $filtered->get_headers();
        $cacheControl = $headers['Cache-Control'] ?? '';

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertSame('no-cache', $headers['Pragma'] ?? '');
        $this->assertSame('0', $headers['Expires'] ?? '');
    }

    private function makeSignedRequest(): WP_REST_Request
    {
        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical(
            'POST',
            '/defyn-connector/v1/links/scan',
            $timestamp,
            $nonce,
            ''
        );
        $sig = base64_encode(sodium_crypto_sign_detached(
            $canonical,
            base64_decode($this->privateKeyBase64)
        ));

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/links/scan');
        $request->set_header('X-Defyn-Timestamp', $timestamp);
        $request->set_header('X-Defyn-Nonce',     $nonce);
        $request->set_header('X-Defyn-Signature', $sig);

        return $request;
    }
}
