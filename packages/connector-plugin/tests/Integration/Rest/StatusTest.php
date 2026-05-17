<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Activation;
use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Task 8: GET /defyn-connector/v1/status (spec § 5.1).
 *
 * Verifies the route is registered, gated by VerifySignatureMiddleware (Task 6),
 * and returns the Collector (Task 7) snapshot to a signed caller.
 *
 * @group integration
 */
final class StatusTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        (new ConnectorState())->reset();
        Activation::activate();
        do_action('rest_api_init');
    }

    public function testSignedRequestReturnsSiteSnapshot(): void
    {
        $kp      = sodium_crypto_sign_keypair();
        $privRaw = sodium_crypto_sign_secretkey($kp);
        $pubB64  = base64_encode(sodium_crypto_sign_publickey($kp));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => $pubB64,
        ]);

        $ts    = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canon = Signer::canonical('GET', '/defyn-connector/v1/status', $ts, $nonce, '');
        $sig   = base64_encode(sodium_crypto_sign_detached($canon, $privRaw));

        $request = new WP_REST_Request('GET', '/defyn-connector/v1/status');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());

        $body = $response->get_data();
        self::assertIsArray($body);
        self::assertArrayHasKey('wp_version', $body);
        self::assertArrayHasKey('plugin_counts', $body);
        self::assertSame(get_bloginfo('version'), $body['wp_version']);
    }
}
