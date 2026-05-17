<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Activation;
use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Task 9: GET /defyn-connector/v1/heartbeat (spec § 5.1).
 *
 * Lightweight liveness probe used by the dashboard's HealthService.
 * Gated by VerifySignatureMiddleware (Task 6); returns {ok, server_time}
 * without collecting any SiteInfo.
 *
 * @group integration
 */
final class HeartbeatTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        (new ConnectorState())->reset();
        Activation::activate();
        do_action('rest_api_init');
    }

    public function testSignedRequestReturnsOkAndServerTime(): void
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
        $canon = Signer::canonical('GET', '/defyn-connector/v1/heartbeat', $ts, $nonce, '');
        $sig   = base64_encode(sodium_crypto_sign_detached($canon, $privRaw));

        $request = new WP_REST_Request('GET', '/defyn-connector/v1/heartbeat');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);

        $before   = time();
        $response = rest_do_request($request);
        $after    = time();

        self::assertSame(200, $response->get_status());

        $body = $response->get_data();
        self::assertIsArray($body);
        self::assertTrue($body['ok']);
        self::assertIsInt($body['server_time']);
        self::assertGreaterThanOrEqual($before, $body['server_time']);
        self::assertLessThanOrEqual($after, $body['server_time']);
    }
}
