<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Activation;
use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * v0.2.5: GET /defyn-connector/v1/upgrade-diagnostics.
 *
 * Read-only host environment probe used to diagnose why updates no-op/502 on
 * locked-down hosts (e.g. WP Engine) without running a real upgrade. Gated by
 * VerifySignatureMiddleware like the other signed endpoints; returns a stable
 * shape ({connector_version, server_time, filesystem, constants, host}).
 *
 * @group integration
 */
final class UpgradeDiagnosticsTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        (new ConnectorState())->reset();
        Activation::activate();
        do_action('rest_api_init');
    }

    public function testSignedRequestReturnsDiagnosticsShape(): void
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
        $canon = Signer::canonical('GET', '/defyn-connector/v1/upgrade-diagnostics', $ts, $nonce, '');
        $sig   = base64_encode(sodium_crypto_sign_detached($canon, $privRaw));

        $request = new WP_REST_Request('GET', '/defyn-connector/v1/upgrade-diagnostics');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());

        $body = $response->get_data();
        self::assertIsArray($body);
        self::assertArrayHasKey('filesystem', $body);
        self::assertArrayHasKey('constants', $body);
        self::assertArrayHasKey('host', $body);

        // Filesystem section reports the writability the dashboard needs to
        // decide Path A (writable) vs Path B (locked host).
        self::assertArrayHasKey('plugin_dir_writable', $body['filesystem']);
        self::assertIsBool($body['filesystem']['plugin_dir_writable']);

        // Constants section never throws on undefined constants.
        self::assertArrayHasKey('DISALLOW_FILE_MODS', $body['constants']);
        self::assertIsBool($body['constants']['DISALLOW_FILE_MODS']);

        // Host section carries the PHP version and WP Engine flag.
        self::assertArrayHasKey('php_version', $body['host']);
        self::assertArrayHasKey('is_wpengine', $body['host']);
        self::assertIsBool($body['host']['is_wpengine']);
    }

    public function testUnsignedRequestIsRejected(): void
    {
        (new ConnectorState())->update(['state' => 'connected']);

        $request  = new WP_REST_Request('GET', '/defyn-connector/v1/upgrade-diagnostics');
        $response = rest_do_request($request);

        self::assertGreaterThanOrEqual(400, $response->get_status());
        self::assertNotSame(200, $response->get_status());
    }
}
