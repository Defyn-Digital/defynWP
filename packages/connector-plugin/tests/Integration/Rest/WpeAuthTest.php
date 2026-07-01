<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Activation;
use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use Defyn\Connector\Wpe\WpeAuth;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * v0.2.6: GET /defyn-connector/v1/wpe-auth.
 *
 * The test harness is NOT on WP Engine (WPE_APIKEY undefined), so the endpoint
 * must report is_wpengine=false and issue no cookies. This locks the
 * off-WP-Engine contract (no cookies ever leave a non-WPE site). The on-WPE
 * cookie minting is validated end-to-end against the live WP Engine site.
 *
 * @group integration
 */
final class WpeAuthTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        (new ConnectorState())->reset();
        Activation::activate();
        do_action('rest_api_init');
    }

    public function testHelperReportsNotWpEngineInTestEnv(): void
    {
        self::assertFalse(WpeAuth::isWpEngine());
        self::assertSame([], WpeAuth::cookies());
    }

    public function testSignedRequestReturnsNoCookiesOffWpEngine(): void
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
        $canon = Signer::canonical('GET', '/defyn-connector/v1/wpe-auth', $ts, $nonce, '');
        $sig   = base64_encode(sodium_crypto_sign_detached($canon, $privRaw));

        $request = new WP_REST_Request('GET', '/defyn-connector/v1/wpe-auth');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        $body = $response->get_data();
        self::assertIsArray($body);
        self::assertArrayHasKey('is_wpengine', $body);
        self::assertFalse($body['is_wpengine']);
        self::assertSame([], $body['cookies']);
        self::assertSame(0, $body['expires_at']);
    }

    public function testUnsignedRequestIsRejected(): void
    {
        (new ConnectorState())->update(['state' => 'connected']);
        $response = rest_do_request(new WP_REST_Request('GET', '/defyn-connector/v1/wpe-auth'));
        self::assertGreaterThanOrEqual(400, $response->get_status());
        self::assertNotSame(200, $response->get_status());
    }
}
