<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use Defyn\Connector\Rest\ThemesRefreshController;
use WP_REST_Request;
use WP_UnitTestCase;

final class ThemesRefreshTest extends WP_UnitTestCase
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
            register_rest_route('defyn-connector/v1', '/themes/refresh', [
                'methods'             => 'POST',
                'callback'            => [new ThemesRefreshController(), 'handle'],
                'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
            ]);
        });
        do_action('rest_api_init');
    }

    public function testRefreshCallsWpUpdateThemesAndReturnsList(): void
    {
        $called = false;
        add_filter('pre_set_site_transient_update_themes', static function ($value) use (&$called) {
            $called = true;
            $fake          = new \stdClass();
            $fake->response = [
                (string) get_stylesheet() => ['new_version' => '1.99'],
            ];
            return $fake;
        });

        $res = $this->sendSigned();

        $this->assertTrue($called, 'wp_update_themes() must run');
        $this->assertSame(200, $res->get_status());
        $this->assertArrayHasKey('themes', $res->get_data());
        $this->assertArrayHasKey('server_time', $res->get_data());
    }

    public function testRefreshFailureReturns502(): void
    {
        // Intercept the transient set and block it to simulate wp_update_themes() failure.
        add_filter('pre_set_site_transient_update_themes', static function ($value, $transient) {
            return false; // Block the transient from being set
        }, 10, 2);

        // Also ensure it stays deleted
        add_action('set_site_transient_update_themes', static function () {
            delete_site_transient('update_themes');
        });

        $res = $this->sendSigned();

        $this->assertSame(502, $res->get_status());
        $this->assertSame('themes.refresh_failed', $res->get_data()['error']['code']);
    }

    public function testUnsignedRequestReturns401(): void
    {
        $request = new WP_REST_Request('POST', '/defyn-connector/v1/themes/refresh');
        $res = rest_do_request($request);
        $this->assertSame(401, $res->get_status());
    }

    private function sendSigned(): \WP_REST_Response
    {
        $ts        = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical('POST', '/defyn-connector/v1/themes/refresh', $ts, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached(
            $canonical,
            base64_decode($this->privateKeyBase64)
        ));

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/themes/refresh');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);
        return rest_do_request($request);
    }
}
