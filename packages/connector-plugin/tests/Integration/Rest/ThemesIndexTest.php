<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use Defyn\Connector\Rest\ThemesController;
use WP_REST_Request;
use WP_UnitTestCase;

final class ThemesIndexTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_themes');

        $keypair = sodium_crypto_sign_keypair();
        $this->privateKeyBase64 = base64_encode(sodium_crypto_sign_secretkey($keypair));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'connected_at'         => gmdate('Y-m-d H:i:s'),
        ]);

        add_action('rest_api_init', static function (): void {
            register_rest_route('defyn-connector/v1', '/themes', [
                'methods'             => 'GET',
                'callback'            => [new ThemesController(), 'handle'],
                'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
            ]);
        });
        do_action('rest_api_init');
    }

    public function testSignedGetReturnsThemesPayload(): void
    {
        $res = $this->sendSigned();

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertArrayHasKey('themes', $data);
        $this->assertArrayHasKey('server_time', $data);
        $this->assertIsInt($data['server_time']);

        foreach ($data['themes'] as $theme) {
            $this->assertArrayHasKey('slug', $theme);
            $this->assertArrayHasKey('parent_slug', $theme);
            $this->assertArrayHasKey('is_active', $theme);
        }
    }

    public function testUnsignedRequestReturns401(): void
    {
        $request = new WP_REST_Request('GET', '/defyn-connector/v1/themes');
        $res = rest_do_request($request);
        $this->assertSame(401, $res->get_status());
    }

    private function sendSigned(): \WP_REST_Response
    {
        $ts        = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical('GET', '/defyn-connector/v1/themes', $ts, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached(
            $canonical,
            base64_decode($this->privateKeyBase64)
        ));

        $request = new WP_REST_Request('GET', '/defyn-connector/v1/themes');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);
        return rest_do_request($request);
    }
}
