<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Activation;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * F6 Task 1 (F5 carry-forward): when the persisted `site_private_key` is
 * corrupted (e.g. manual DB edit), `Signer::sign()` throws
 * `\InvalidArgumentException`. The connector must catch that and return the
 * standard `{error:{code,message}}` envelope with code
 * `connector.signing_failed` and HTTP 500, instead of bubbling a generic 500
 * out of WordPress.
 *
 * @group integration
 */
final class ConnectControllerSigningFailureTest extends WP_UnitTestCase
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

    public function testReturnsSigningFailedEnvelopeWhenPrivateKeyCorrupt(): void
    {
        $this->state->update([
            'state'             => 'awaiting-handshake',
            'connection_code'   => 'ABCDEFGHJKMN',
            'site_nonce'        => str_repeat('a', 64),
            'code_expires_at'   => time() + 60,
            'site_private_key'  => 'not-a-valid-base64-key',
            'site_public_key'   => base64_encode(random_bytes(32)),
        ]);

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'code'                 => 'ABCDEFGHJKMN',
            'dashboard_public_key' => base64_encode(random_bytes(32)),
            'callback_challenge'   => base64_encode(random_bytes(32)),
        ]));

        $response = rest_do_request($request);

        self::assertSame(500, $response->get_status());
        self::assertSame('connector.signing_failed', $response->get_data()['error']['code']);
    }
}
