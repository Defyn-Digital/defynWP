<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Activation;
use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * P2.1 — GET /defyn-connector/v1/plugins (spec § 3.1).
 *
 * @group integration
 */
final class PluginsListTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        (new ConnectorState())->reset();
        Activation::activate();
        do_action('rest_api_init');
    }

    public function testUnsignedRequestReturns401(): void
    {
        // The middleware short-circuits to 404 connector.not_connected when
        // state != connected, BEFORE checking signature headers. To test the
        // signature gate itself (this test's purpose), establish a connected
        // state first — same pattern as VerifySignatureMiddlewareTest.
        $pubRaw = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode($pubRaw),
            'connected_at'         => gmdate('c'),
        ]);

        $req = new WP_REST_Request('GET', '/defyn-connector/v1/plugins');
        $res = rest_do_request($req);
        self::assertSame(401, $res->get_status());
    }

    public function testSignedRequestReturnsPluginsPayload(): void
    {
        $kp      = sodium_crypto_sign_keypair();
        $privRaw = sodium_crypto_sign_secretkey($kp);
        $pubRaw  = sodium_crypto_sign_publickey($kp);

        $state = new ConnectorState();
        $state->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode($pubRaw),
            'connected_at'         => gmdate('c'),
        ]);

        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(8));
        $canonical = Signer::canonical('GET', '/defyn-connector/v1/plugins', $timestamp, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, $privRaw));

        $req = new WP_REST_Request('GET', '/defyn-connector/v1/plugins');
        $req->set_header('X-Defyn-Timestamp', $timestamp);
        $req->set_header('X-Defyn-Nonce',     $nonce);
        $req->set_header('X-Defyn-Signature', $sig);

        $res  = rest_do_request($req);
        $body = $res->get_data();

        self::assertSame(200, $res->get_status());
        self::assertArrayHasKey('plugins',     $body);
        self::assertArrayHasKey('truncated',   $body);
        self::assertArrayHasKey('server_time', $body);
        self::assertIsInt($body['server_time']);
    }
}
