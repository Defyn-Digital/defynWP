<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Activation;
use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class ThemesCacheHeadersTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_themes');
        delete_transient('defyn_connector_upgrade_in_flight');
        wp_cache_delete('defyn_connector_upgrade_in_flight', 'transient');

        (new ConnectorState())->reset();
        Activation::activate();
        do_action('rest_api_init');

        $keypair = sodium_crypto_sign_keypair();
        $this->privateKeyBase64 = base64_encode(sodium_crypto_sign_secretkey($keypair));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'connected_at'         => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function testGetThemesGetsNoStoreHeaders(): void
    {
        $request = $this->makeSignedRequest('GET', '/defyn-connector/v1/themes');
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());

        $filtered = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);
        $this->assertInstanceOf(WP_REST_Response::class, $filtered);
        $this->assertCacheControlNoStore($filtered);
    }

    public function testPostThemesRefreshGetsNoStoreHeaders(): void
    {
        add_filter('pre_set_site_transient_update_themes', static function () {
            $fake = new \stdClass();
            $fake->response = [];
            return $fake;
        });

        $request = $this->makeSignedRequest('POST', '/defyn-connector/v1/themes/refresh');
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());

        $filtered = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);
        $this->assertCacheControlNoStore($filtered);
    }

    public function testPostThemeUpdateGetsNoStoreHeaders(): void
    {
        $stylesheet = (string) get_stylesheet();
        $update = new \stdClass();
        $update->response = [$stylesheet => ['theme' => $stylesheet, 'new_version' => '99.9', 'package' => 'https://example.test/theme.zip']];
        set_site_transient('update_themes', $update);

        $controller = new \Defyn\Connector\Rest\ThemeUpdateController(
            new \Defyn\Connector\SiteInfo\ThemeUpgraderService(
                fn () => new class { public function upgrade(string $stylesheet) { return true; } }
            )
        );
        register_rest_route('defyn-connector/v1', '/themes/(?P<slug>[a-z0-9-]{1,80})/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);

        $request = $this->makeSignedRequest('POST', '/defyn-connector/v1/themes/' . $stylesheet . '/update');
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());

        $filtered = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);
        $this->assertCacheControlNoStore($filtered);
    }

    private function assertCacheControlNoStore(WP_REST_Response $response): void
    {
        $cc = $response->get_headers()['Cache-Control'] ?? '';
        $this->assertStringContainsString('no-store', $cc);
        $this->assertStringContainsString('no-cache', $cc);
        $this->assertStringContainsString('private', $cc);
        $this->assertSame('no-cache', $response->get_headers()['Pragma'] ?? '');
        $this->assertSame('0', $response->get_headers()['Expires'] ?? '');
    }

    private function makeSignedRequest(string $method, string $path): WP_REST_Request
    {
        $ts        = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical($method, $path, $ts, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, base64_decode($this->privateKeyBase64)));

        $request = new WP_REST_Request($method, $path);
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);
        return $request;
    }
}
