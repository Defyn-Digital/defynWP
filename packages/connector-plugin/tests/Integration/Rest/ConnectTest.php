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

    public function testValidCodeReturns200AndMarksCodeConsumed(): void
    {
        $code = 'ABCDEFGH2345';
        $this->state->update([
            'state'           => 'awaiting-handshake',
            'connection_code' => $code,
            'site_nonce'      => 'nonce-base64',
            'code_created_at' => time(),
            'code_expires_at' => time() + 600,
        ]);

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['code' => $code]));

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        self::assertSame(['ok' => true], $response->get_data());

        $after = $this->state->all();
        self::assertSame('code-consumed', $after['state']);
        self::assertArrayHasKey('code_consumed_at', $after);
    }

    public function testMissingCodeReturns400WithEnvelope(): void
    {
        $this->state->update([
            'state'           => 'awaiting-handshake',
            'connection_code' => 'ABCDEFGH2345',
            'code_expires_at' => time() + 600,
        ]);

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([]));

        $response = rest_do_request($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('connector.missing_code', $response->get_data()['error']['code']);
    }

    public function testNoCodeGeneratedReturns404(): void
    {
        // state stays at "unconfigured" from Activation
        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['code' => 'ABCDEFGH2345']));

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
        $request->set_body(json_encode(['code' => 'WRONGCODE234']));

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
        $request->set_body(json_encode(['code' => 'ABCDEFGH2345']));

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
        $request->set_body(json_encode(['code' => 'ABCDEFGH2345']));

        $response = rest_do_request($request);

        self::assertSame(409, $response->get_status());
        self::assertSame('connector.code_consumed', $response->get_data()['error']['code']);
    }
}
