<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use Defyn\Connector\Rest\RestRouter;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class CoreCacheHeadersTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_core');
        delete_transient('defyn_connector_upgrade_in_flight');
        wp_cache_delete('defyn_connector_upgrade_in_flight', 'transient');
        add_filter('pre_set_site_transient_update_core', static fn ($value) => $value, 10, 1);

        $keypair = sodium_crypto_sign_keypair();
        $this->privateKeyBase64 = base64_encode(sodium_crypto_sign_secretkey($keypair));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'connected_at'         => gmdate('Y-m-d H:i:s'),
        ]);

        add_action('rest_api_init', static function (): void {
            (new RestRouter())->register();
        });
        do_action('rest_api_init');
    }

    public function testPostCoreRefreshGetsNoStoreHeaders(): void
    {
        add_filter('pre_set_site_transient_update_core', static function () {
            $fake = new \stdClass();
            $fake->updates = [];
            return $fake;
        });

        $request = $this->makeSignedRequest('POST', '/defyn-connector/v1/core/refresh');
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());

        $filtered = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);
        $this->assertCacheControlNoStore($filtered);
    }

    public function testPostCoreUpdateGetsNoStoreHeaders(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $target = $maj . '.' . $min . '.1';
        $update = new \stdClass();
        $update->updates = [(object) [
            'response' => 'upgrade',
            'current'  => $target,
            'version'  => $target,
            'package'  => 'https://example.test/wp.zip',
            'locale'   => 'en_US',
        ]];
        $update->version_checked = $current;
        set_site_transient('update_core', $update);

        $controller = new \Defyn\Connector\Rest\CoreUpdateController(
            new \Defyn\Connector\SiteInfo\CoreUpgraderService(
                fn () => new class { public function upgrade($update) { return true; } }
            )
        );
        add_action('rest_api_init', static function () use ($controller): void {
            register_rest_route('defyn-connector/v1', '/core/update', [
                'methods'             => 'POST',
                'callback'            => [$controller, 'handle'],
                'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
            ], true);
        });
        do_action('rest_api_init');

        $request = $this->makeSignedRequest('POST', '/defyn-connector/v1/core/update');
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
