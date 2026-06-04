<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Activation;
use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * P2.1 — both new endpoints ship Cache-Control: no-store via the v0.1.2
 * applyNoCacheHeaders filter. Regression guard against the WP.com Batcache
 * issue that produced stale 404 responses on /status during P2.1 discovery.
 *
 * @group integration
 */
final class PluginsCacheHeadersTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        (new ConnectorState())->reset();
        Activation::activate();
        do_action('rest_api_init');
    }

    public function testGetPluginsShipsNoStoreHeader(): void
    {
        $headers = $this->signedRequestHeaders('GET', '/defyn-connector/v1/plugins');
        self::assertStringContainsString('no-store', strtolower($headers['cache-control'] ?? ''));
    }

    public function testPostPluginsRefreshShipsNoStoreHeader(): void
    {
        $headers = $this->signedRequestHeaders('POST', '/defyn-connector/v1/plugins/refresh');
        self::assertStringContainsString('no-store', strtolower($headers['cache-control'] ?? ''));
    }

    /**
     * @return array<string, string>
     */
    private function signedRequestHeaders(string $method, string $route): array
    {
        $kp      = sodium_crypto_sign_keypair();
        $privRaw = sodium_crypto_sign_secretkey($kp);
        $pubRaw  = sodium_crypto_sign_publickey($kp);

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode($pubRaw),
            'connected_at'         => gmdate('c'),
        ]);

        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(8));
        $canonical = Signer::canonical($method, $route, $timestamp, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, $privRaw));

        $req = new WP_REST_Request($method, $route);
        $req->set_header('X-Defyn-Timestamp', $timestamp);
        $req->set_header('X-Defyn-Nonce',     $nonce);
        $req->set_header('X-Defyn-Signature', $sig);

        // rest_do_request() calls WP_REST_Server::dispatch() directly, which
        // skips the rest_post_dispatch filter pipeline that applyNoCacheHeaders
        // is hooked on. Invoke the filter manually here to exercise the same
        // code path production HTTP traffic uses via serve_request().
        $res = rest_do_request($req);
        $res = apply_filters('rest_post_dispatch', $res, rest_get_server(), $req);
        return array_change_key_case($res->get_headers(), CASE_LOWER);
    }
}
