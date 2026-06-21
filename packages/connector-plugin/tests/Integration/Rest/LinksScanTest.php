<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Activation;
use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * P7.1 — POST /defyn-connector/v1/links/scan (signed).
 *
 * @group integration
 */
final class LinksScanTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        (new ConnectorState())->reset();
        Activation::activate();
        do_action('rest_api_init');
    }

    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request');
        parent::tearDown();
    }

    private function connectWithKeypair(): string
    {
        $kp = sodium_crypto_sign_keypair();
        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode(sodium_crypto_sign_publickey($kp)),
            'connected_at'         => gmdate('c'),
        ]);
        return $kp;
    }

    public function testUnsignedRequestReturns401(): void
    {
        $this->connectWithKeypair(); // connected, but no signature headers → 401 (not 404)
        $req = new WP_REST_Request('POST', '/defyn-connector/v1/links/scan');
        $res = rest_do_request($req);
        self::assertSame(401, $res->get_status());
    }

    public function testSignedRequestReturnsScanPayload(): void
    {
        add_filter('pre_http_request', fn() => ['response' => ['code' => 200], 'body' => ''], 10, 0);
        $kp   = $this->connectWithKeypair();
        $priv = sodium_crypto_sign_secretkey($kp);

        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(8));
        $canonical = Signer::canonical('POST', '/defyn-connector/v1/links/scan', $timestamp, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, $priv));

        $req = new WP_REST_Request('POST', '/defyn-connector/v1/links/scan');
        $req->set_header('X-Defyn-Timestamp', $timestamp);
        $req->set_header('X-Defyn-Nonce',     $nonce);
        $req->set_header('X-Defyn-Signature', $sig);

        $res  = rest_do_request($req);
        self::assertSame(200, $res->get_status());
        $body = $res->get_data();
        self::assertArrayHasKey('links',         $body);
        self::assertArrayHasKey('scanned_posts', $body);
        self::assertArrayHasKey('truncated',     $body);
        self::assertArrayHasKey('server_time',   $body);
    }
}
