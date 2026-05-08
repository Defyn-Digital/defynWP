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
}
