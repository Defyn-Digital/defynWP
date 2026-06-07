<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use Defyn\Connector\Rest\CoreRefreshController;
use WP_REST_Request;
use WP_UnitTestCase;

final class CoreRefreshTest extends WP_UnitTestCase
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

        add_action('rest_api_init', static function (): void {
            register_rest_route('defyn-connector/v1', '/core/refresh', [
                'methods'             => 'POST',
                'callback'            => [new CoreRefreshController(), 'handle'],
                'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
            ]);
        });
        do_action('rest_api_init');
    }

    public function testRefreshCallsWpVersionCheckAndReturnsCorePayload(): void
    {
        $res = $this->sendSigned();

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertArrayHasKey('update_available', $data);
        $this->assertArrayHasKey('update_version', $data);
        $this->assertArrayHasKey('is_minor_update', $data);
        $this->assertArrayHasKey('is_auto_update_enabled', $data);
        $this->assertArrayHasKey('server_time', $data);
        $this->assertIsBool($data['update_available']);
        $this->assertIsBool($data['is_minor_update']);
    }

    public function testRefreshFailureReturns502(): void
    {
        // Intercept the transient set and block it to simulate wp_version_check() failure.
        add_filter('pre_set_site_transient_update_core', static function ($value, $transient) {
            return false; // Block the transient from being set
        }, 10, 2);

        // Also ensure it stays deleted if somehow set
        add_action('set_site_transient_update_core', static function () {
            delete_site_transient('update_core');
        });

        $res = $this->sendSigned();

        $this->assertSame(502, $res->get_status());
        $this->assertSame('core.refresh_failed', $res->get_data()['error']['code']);
    }

    private function sendSigned(): \WP_REST_Response
    {
        $ts        = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical('POST', '/defyn-connector/v1/core/refresh', $ts, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached(
            $canonical,
            base64_decode($this->privateKeyBase64)
        ));

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/core/refresh');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);
        return rest_do_request($request);
    }
}
