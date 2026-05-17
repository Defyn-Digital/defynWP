<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Activation;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class ConnectTest extends WP_UnitTestCase
{
    private ConnectorState $state;

    public function setUp(): void
    {
        parent::setUp();
        $this->state = new ConnectorState();
        $this->state->reset();
        Activation::activate();
        do_action('rest_api_init');
    }

    // -------------------------------------------------------------------------
    // Happy path (F5 shape)
    // -------------------------------------------------------------------------

    public function testValidHandshakeReturnsSignedResponseAndMarksConnected(): void
    {
        $code = 'ABCDEFGH2345';
        $nonce = base64_encode(random_bytes(32));
        $this->state->update([
            'state'           => 'awaiting-handshake',
            'connection_code' => $code,
            'site_nonce'      => $nonce,
            'code_created_at' => time(),
            'code_expires_at' => time() + 600,
        ]);

        $dashboardPair = sodium_crypto_sign_keypair();
        $dashboardPub  = base64_encode(sodium_crypto_sign_publickey($dashboardPair));
        $challenge     = base64_encode(random_bytes(32));

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'code'                 => $code,
            'dashboard_public_key' => $dashboardPub,
            'callback_challenge'   => $challenge,
        ]));

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertArrayHasKey('site_public_key', $data);
        self::assertArrayHasKey('challenge_signature', $data);
        self::assertArrayHasKey('site_url', $data);
        self::assertArrayHasKey('site_name', $data);

        // Signature must verify against the returned site_public_key over the raw challenge.
        $sigRaw = base64_decode($data['challenge_signature'], true);
        $pubRaw = base64_decode($data['site_public_key'], true);
        self::assertTrue(sodium_crypto_sign_verify_detached($sigRaw, $challenge, $pubRaw));

        // State transitioned to 'connected'.
        $after = $this->state->all();
        self::assertSame('connected', $after['state']);
        self::assertSame($dashboardPub, $after['dashboard_public_key']);
        self::assertArrayHasKey('connected_at', $after);
    }

    // -------------------------------------------------------------------------
    // F4 error-path tests — updated to include the two new required fields
    // -------------------------------------------------------------------------

    public function testMissingCodeReturns400WithEnvelope(): void
    {
        $this->state->update([
            'state'           => 'awaiting-handshake',
            'connection_code' => 'ABCDEFGH2345',
            'code_expires_at' => time() + 600,
        ]);

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'dashboard_public_key' => base64_encode(random_bytes(32)),
            'callback_challenge'   => base64_encode(random_bytes(32)),
        ]));

        $response = rest_do_request($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('connector.missing_code', $response->get_data()['error']['code']);
    }

    public function testNoCodeGeneratedReturns404(): void
    {
        // state stays at "unconfigured" from Activation
        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'code'                 => 'ABCDEFGH2345',
            'dashboard_public_key' => base64_encode(random_bytes(32)),
            'callback_challenge'   => base64_encode(random_bytes(32)),
        ]));

        $response = rest_do_request($request);

        self::assertSame(404, $response->get_status());
        self::assertSame('connector.no_pending_code', $response->get_data()['error']['code']);
    }

    public function testInvalidCodeReturns401(): void
    {
        $this->state->update([
            'state'           => 'awaiting-handshake',
            'connection_code' => 'ABCDEFGH2345',
            'code_expires_at' => time() + 600,
        ]);

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'code'                 => 'WRONGCODE234',
            'dashboard_public_key' => base64_encode(random_bytes(32)),
            'callback_challenge'   => base64_encode(random_bytes(32)),
        ]));

        $response = rest_do_request($request);

        self::assertSame(401, $response->get_status());
        self::assertSame('connector.invalid_code', $response->get_data()['error']['code']);
    }

    public function testExpiredCodeReturns410(): void
    {
        $this->state->update([
            'state'           => 'awaiting-handshake',
            'connection_code' => 'ABCDEFGH2345',
            'code_expires_at' => time() - 1,
        ]);

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'code'                 => 'ABCDEFGH2345',
            'dashboard_public_key' => base64_encode(random_bytes(32)),
            'callback_challenge'   => base64_encode(random_bytes(32)),
        ]));

        $response = rest_do_request($request);

        self::assertSame(410, $response->get_status());
        self::assertSame('connector.code_expired', $response->get_data()['error']['code']);
    }

    public function testAlreadyConsumedCodeReturns409(): void
    {
        $this->state->update([
            'state'             => 'code-consumed',
            'connection_code'   => 'ABCDEFGH2345',
            'code_expires_at'   => time() + 600,
            'code_consumed_at'  => time() - 5,
        ]);

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'code'                 => 'ABCDEFGH2345',
            'dashboard_public_key' => base64_encode(random_bytes(32)),
            'callback_challenge'   => base64_encode(random_bytes(32)),
        ]));

        $response = rest_do_request($request);

        self::assertSame(409, $response->get_status());
        self::assertSame('connector.code_consumed', $response->get_data()['error']['code']);
    }

    /**
     * Locks in the spec § 8 step 7 branch ordering ("exist, not expired, not consumed"):
     * when a code is BOTH expired AND previously consumed, expiry wins (410, not 409).
     */
    public function testConsumedAndExpiredCodeReturns410ExpiryWins(): void
    {
        $this->state->update([
            'state'             => 'code-consumed',
            'connection_code'   => 'ABCDEFGH2345',
            'code_expires_at'   => time() - 1,       // expired
            'code_consumed_at'  => time() - 600,     // also consumed (earlier)
        ]);

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'code'                 => 'ABCDEFGH2345',
            'dashboard_public_key' => base64_encode(random_bytes(32)),
            'callback_challenge'   => base64_encode(random_bytes(32)),
        ]));

        $response = rest_do_request($request);

        self::assertSame(410, $response->get_status());
        self::assertSame('connector.code_expired', $response->get_data()['error']['code']);
    }

    // -------------------------------------------------------------------------
    // F5 new error-path tests
    // -------------------------------------------------------------------------

    public function testMissingDashboardPublicKeyReturns400(): void
    {
        $this->state->update([
            'state'           => 'awaiting-handshake',
            'connection_code' => 'ABCDEFGH2345',
            'site_nonce'      => base64_encode(random_bytes(32)),
            'code_expires_at' => time() + 600,
        ]);

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'code'               => 'ABCDEFGH2345',
            'callback_challenge' => base64_encode(random_bytes(32)),
        ]));

        $response = rest_do_request($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('connector.missing_dashboard_key', $response->get_data()['error']['code']);
    }

    public function testMissingCallbackChallengeReturns400(): void
    {
        $this->state->update([
            'state'           => 'awaiting-handshake',
            'connection_code' => 'ABCDEFGH2345',
            'site_nonce'      => base64_encode(random_bytes(32)),
            'code_expires_at' => time() + 600,
        ]);

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'code'                 => 'ABCDEFGH2345',
            'dashboard_public_key' => base64_encode(random_bytes(32)),
        ]));

        $response = rest_do_request($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('connector.missing_challenge', $response->get_data()['error']['code']);
    }

    public function testMalformedDashboardPublicKeyReturns400(): void
    {
        $this->state->update([
            'state'           => 'awaiting-handshake',
            'connection_code' => 'ABCDEFGH2345',
            'site_nonce'      => base64_encode(random_bytes(32)),
            'code_expires_at' => time() + 600,
        ]);

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'code'                 => 'ABCDEFGH2345',
            'dashboard_public_key' => 'not-valid-base64!!!',
            'callback_challenge'   => base64_encode(random_bytes(32)),
        ]));

        $response = rest_do_request($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('connector.invalid_dashboard_key', $response->get_data()['error']['code']);
    }
}
