<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Activation;
use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware;
use Defyn\Connector\Storage\ConnectorState;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Task 6: VerifySignatureMiddleware (spec § 5.2).
 *
 * The 5 route-dispatch tests (heartbeat) intentionally fail until Task 9 wires
 * the /heartbeat route — at that point they go green. The direct-call test
 * gives this commit its own RED→GREEN cycle for the middleware itself.
 *
 * @group integration
 */
final class VerifySignatureMiddlewareTest extends WP_UnitTestCase
{
    private string $dashboardPrivateKeyRaw = '';
    private string $dashboardPublicKeyB64  = '';

    public function setUp(): void
    {
        parent::setUp();

        (new ConnectorState())->reset();
        Activation::activate();
        do_action('rest_api_init');

        $kp = sodium_crypto_sign_keypair();
        $this->dashboardPrivateKeyRaw = sodium_crypto_sign_secretkey($kp);
        $this->dashboardPublicKeyB64  = base64_encode(sodium_crypto_sign_publickey($kp));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => $this->dashboardPublicKeyB64,
        ]);
    }

    /** @return array<string,string> */
    private function signedHeaders(string $method, string $path, string $body = ''): array
    {
        $ts    = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canon = Signer::canonical($method, $path, $ts, $nonce, $body);
        $sig   = base64_encode(sodium_crypto_sign_detached($canon, $this->dashboardPrivateKeyRaw));

        return [
            'X-Defyn-Timestamp' => $ts,
            'X-Defyn-Nonce'     => $nonce,
            'X-Defyn-Signature' => $sig,
        ];
    }

    /** @param array<string,string> $headers */
    private function dispatch(string $method, string $route, array $headers = []): \WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }
        return rest_do_request($request);
    }

    // -------------------------------------------------------------------------
    // Direct-call test — exercises the middleware without route dispatch so
    // this task has its own RED→GREEN cycle ahead of Task 9.
    // -------------------------------------------------------------------------

    public function testDirectCheckReturnsNotConnectedWhenStateUnconfigured(): void
    {
        (new ConnectorState())->update(['state' => 'unconfigured', 'dashboard_public_key' => '']);

        $request = new WP_REST_Request('GET', '/defyn-connector/v1/heartbeat');
        $request->set_route('/defyn-connector/v1/heartbeat');

        $result = VerifySignatureMiddleware::check($request);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('connector.not_connected', $result->get_error_code());
        self::assertSame(404, $result->get_error_data()['status'] ?? null);
    }

    // -------------------------------------------------------------------------
    // Route-dispatch tests — fail until Task 9 registers /heartbeat.
    // -------------------------------------------------------------------------

    public function testValidSignaturePassesThroughToHeartbeat(): void
    {
        $headers  = $this->signedHeaders('GET', '/defyn-connector/v1/heartbeat');
        $response = $this->dispatch('GET', '/defyn-connector/v1/heartbeat', $headers);

        self::assertSame(200, $response->get_status());
        self::assertTrue($response->get_data()['ok']);
    }

    public function testMissingHeadersReturnsSignatureMissing(): void
    {
        $response = $this->dispatch('GET', '/defyn-connector/v1/heartbeat', []);
        self::assertSame(401, $response->get_status());
        self::assertSame('connector.signature_missing', $response->get_data()['error']['code']);
    }

    public function testExpiredTimestampReturnsSignatureExpired(): void
    {
        $ts    = (string) (time() - 1000);
        $nonce = bin2hex(random_bytes(16));
        $canon = Signer::canonical('GET', '/defyn-connector/v1/heartbeat', $ts, $nonce, '');
        $sig   = base64_encode(sodium_crypto_sign_detached($canon, $this->dashboardPrivateKeyRaw));

        $response = $this->dispatch('GET', '/defyn-connector/v1/heartbeat', [
            'X-Defyn-Timestamp' => $ts,
            'X-Defyn-Nonce'     => $nonce,
            'X-Defyn-Signature' => $sig,
        ]);

        self::assertSame(401, $response->get_status());
        self::assertSame('connector.signature_expired', $response->get_data()['error']['code']);
    }

    public function testReplayedNonceReturnsSignatureReplay(): void
    {
        $headers = $this->signedHeaders('GET', '/defyn-connector/v1/heartbeat');

        $first  = $this->dispatch('GET', '/defyn-connector/v1/heartbeat', $headers);
        $second = $this->dispatch('GET', '/defyn-connector/v1/heartbeat', $headers);

        self::assertSame(200, $first->get_status());
        self::assertSame(401, $second->get_status());
        self::assertSame('connector.signature_replay', $second->get_data()['error']['code']);
    }

    public function testUnconnectedStateReturnsNotConnected(): void
    {
        (new ConnectorState())->update(['state' => 'unconfigured', 'dashboard_public_key' => '']);

        $headers  = $this->signedHeaders('GET', '/defyn-connector/v1/heartbeat');
        $response = $this->dispatch('GET', '/defyn-connector/v1/heartbeat', $headers);

        self::assertSame(404, $response->get_status());
        self::assertSame('connector.not_connected', $response->get_data()['error']['code']);
    }
}
