<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use Defyn\Connector\Rest\RestRouter;
use WP_REST_Request;
use WP_UnitTestCase;

final class StatusCoreExtensionTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_core');

        $keypair = sodium_crypto_sign_keypair();
        $this->privateKeyBase64 = base64_encode(sodium_crypto_sign_secretkey($keypair));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'connected_at'         => gmdate('Y-m-d H:i:s'),
        ]);

        do_action('rest_api_init');
    }

    public function testStatusIncludesCoreSubObjectWithExpectedKeys(): void
    {
        $res = $this->sendSigned();

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();

        $this->assertArrayHasKey('core', $data);
        $this->assertArrayHasKey('update_available', $data['core']);
        $this->assertArrayHasKey('update_version', $data['core']);
        $this->assertArrayHasKey('is_minor_update', $data['core']);
        $this->assertArrayHasKey('is_auto_update_enabled', $data['core']);
    }

    public function testStatusPreservesExistingKeys(): void
    {
        $res = $this->sendSigned();
        $data = $res->get_data();

        $this->assertArrayHasKey('wp_version', $data);
        $this->assertArrayHasKey('php_version', $data);
        $this->assertArrayHasKey('plugin_counts', $data);
        $this->assertArrayHasKey('theme_counts', $data);
        $this->assertArrayHasKey('ssl_status', $data);
        $this->assertArrayHasKey('server_time', $data);
    }

    private function sendSigned(): \WP_REST_Response
    {
        $ts        = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical('GET', '/defyn-connector/v1/status', $ts, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, base64_decode($this->privateKeyBase64)));

        $request = new WP_REST_Request('GET', '/defyn-connector/v1/status');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);
        return rest_do_request($request);
    }
}
