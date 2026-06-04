<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Activation;
use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * P2.1 — POST /defyn-connector/v1/plugins/refresh (spec § 3.2).
 *
 * @group integration
 */
final class PluginsRefreshTest extends WP_UnitTestCase
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
        // Establish connected state first so the middleware reaches the
        // signature-headers check (same pattern as Task 2 + VerifySignatureMiddlewareTest).
        $pubRaw = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode($pubRaw),
            'connected_at'         => gmdate('c'),
        ]);

        $req = new WP_REST_Request('POST', '/defyn-connector/v1/plugins/refresh');
        $res = rest_do_request($req);
        self::assertSame(401, $res->get_status());
    }

    public function testSignedRequestForcesUpdateCheckAndReturnsPayload(): void
    {
        $kp      = sodium_crypto_sign_keypair();
        $privRaw = sodium_crypto_sign_secretkey($kp);
        $pubRaw  = sodium_crypto_sign_publickey($kp);

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode($pubRaw),
            'connected_at'         => gmdate('c'),
        ]);

        delete_site_transient('update_plugins');

        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(8));
        $canonical = Signer::canonical('POST', '/defyn-connector/v1/plugins/refresh', $timestamp, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, $privRaw));

        $req = new WP_REST_Request('POST', '/defyn-connector/v1/plugins/refresh');
        $req->set_header('X-Defyn-Timestamp', $timestamp);
        $req->set_header('X-Defyn-Nonce',     $nonce);
        $req->set_header('X-Defyn-Signature', $sig);

        $res  = rest_do_request($req);
        $body = $res->get_data();

        self::assertSame(200, $res->get_status());
        self::assertArrayHasKey('plugins',     $body);
        self::assertArrayHasKey('truncated',   $body);
        self::assertArrayHasKey('server_time', $body);

        $after = get_site_transient('update_plugins');
        self::assertNotFalse($after, 'wp_update_plugins() should have populated the transient');
    }
}
