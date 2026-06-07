<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use Defyn\Connector\Rest\CoreUpdateController;
use Defyn\Connector\Rest\PluginUpdateController;
use Defyn\Connector\Rest\ThemeUpdateController;
use Defyn\Connector\SiteInfo\CoreUpgraderService;
use Defyn\Connector\SiteInfo\PluginUpgraderService;
use Defyn\Connector\SiteInfo\ThemeUpgraderService;
use WP_REST_Request;
use WP_UnitTestCase;

final class CoreUpdateLockTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_core');
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        delete_transient('defyn_connector_upgrade_in_flight');
        wp_cache_delete('defyn_connector_upgrade_in_flight', 'transient');
        wp_cache_flush();
        add_filter('pre_set_site_transient_update_core', static fn ($value) => $value, 10, 1);

        $keypair = sodium_crypto_sign_keypair();
        $this->privateKeyBase64 = base64_encode(sodium_crypto_sign_secretkey($keypair));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'connected_at'         => gmdate('Y-m-d H:i:s'),
        ]);

        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $this->seedCoreUpdate($maj . '.' . $min . '.1');
    }

    public function testCoreLockReleasedOnSuccess(): void
    {
        $this->registerCoreWithStub(true);

        $res1 = $this->sendCoreSigned();
        $this->assertSame(200, $res1->get_status());
        $this->assertFalse(get_transient('defyn_connector_upgrade_in_flight'));

        // Reseed since the upgrade success path may clean the transient
        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $this->seedCoreUpdate($maj . '.' . $min . '.1');
        $res2 = $this->sendCoreSigned();
        $this->assertSame(200, $res2->get_status());
    }

    public function testCoreLockReleasedOnFailure(): void
    {
        $this->registerCoreWithStub(false);

        $res = $this->sendCoreSigned();
        $this->assertSame(502, $res->get_status());
        $this->assertFalse(get_transient('defyn_connector_upgrade_in_flight'));
    }

    public function testCoreBlockedByPluginInFlight(): void
    {
        set_transient('defyn_connector_upgrade_in_flight', 'akismet', 600);
        $this->registerCoreWithStub(true);

        $res = $this->sendCoreSigned();

        $this->assertSame(409, $res->get_status());
        $this->assertSame('connector.upgrade_in_progress', $res->get_data()['error']['code']);
        $this->assertStringContainsString('akismet', $res->get_data()['error']['message']);
    }

    public function testCoreBlockedByThemeInFlight(): void
    {
        set_transient('defyn_connector_upgrade_in_flight', 'twentytwentyfive', 600);
        $this->registerCoreWithStub(true);

        $res = $this->sendCoreSigned();

        $this->assertSame(409, $res->get_status());
        $this->assertSame('connector.upgrade_in_progress', $res->get_data()['error']['code']);
        $this->assertStringContainsString('twentytwentyfive', $res->get_data()['error']['message']);
    }

    public function testPluginBlockedByCoreInFlight(): void
    {
        set_transient('defyn_connector_upgrade_in_flight', 'core', 600);

        $this->seedPluginUpdate('hello', 'hello.php', '99.9');
        $controller = new PluginUpdateController(
            new PluginUpgraderService(fn () => new class { public function upgrade(string $pluginFile) { return true; } })
        );
        add_action('rest_api_init', static function () use ($controller): void {
            register_rest_route('defyn-connector/v1', '/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
                'methods'             => 'POST',
                'callback'            => [$controller, 'handle'],
                'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
            ], true);
        });
        do_action('rest_api_init');

        $res = $this->sendSigned('POST', '/defyn-connector/v1/plugins/hello/update');

        $this->assertSame(409, $res->get_status());
        $this->assertSame('plugins.update_in_progress', $res->get_data()['error']['code']);
    }

    public function testThemeBlockedByCoreInFlight(): void
    {
        set_transient('defyn_connector_upgrade_in_flight', 'core', 600);

        $stylesheet = (string) get_stylesheet();
        $themeUpdate = new \stdClass();
        $themeUpdate->response = [
            $stylesheet => ['theme' => $stylesheet, 'new_version' => '99.9', 'package' => 'https://example.test/theme.zip'],
        ];
        set_site_transient('update_themes', $themeUpdate);

        $controller = new ThemeUpdateController(
            new ThemeUpgraderService(fn () => new class { public function upgrade(string $stylesheet) { return true; } })
        );
        add_action('rest_api_init', static function () use ($controller): void {
            register_rest_route('defyn-connector/v1', '/themes/(?P<slug>[a-z0-9-]{1,80})/update', [
                'methods'             => 'POST',
                'callback'            => [$controller, 'handle'],
                'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
            ], true);
        });
        do_action('rest_api_init');

        $res = $this->sendSigned('POST', '/defyn-connector/v1/themes/' . $stylesheet . '/update');

        $this->assertSame(409, $res->get_status());
        $this->assertSame('connector.upgrade_in_progress', $res->get_data()['error']['code']);
    }

    private function registerCoreWithStub(bool $success): void
    {
        $factory = $success
            ? fn () => new class { public function upgrade($update) { return true; } }
            : function (\Defyn\Connector\SiteInfo\CapturingUpgraderSkin $skin) {
                $skin->error('Synthetic test failure.');
                return new class { public function upgrade($update) { return false; } };
            };

        $controller = new CoreUpdateController(new CoreUpgraderService($factory));
        add_action('rest_api_init', static function () use ($controller): void {
            register_rest_route('defyn-connector/v1', '/core/update', [
                'methods'             => 'POST',
                'callback'            => [$controller, 'handle'],
                'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
            ], true);
        });
        do_action('rest_api_init');
    }

    private function sendCoreSigned(): \WP_REST_Response
    {
        return $this->sendSigned('POST', '/defyn-connector/v1/core/update');
    }

    private function sendSigned(string $method, string $path): \WP_REST_Response
    {
        $ts        = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical($method, $path, $ts, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, base64_decode($this->privateKeyBase64)));

        $request = new WP_REST_Request($method, $path);
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);
        return rest_do_request($request);
    }

    private function seedCoreUpdate(string $target): void
    {
        $update = new \stdClass();
        $update->updates = [(object) [
            'response' => 'upgrade',
            'current'  => $target,
            'version'  => $target,
            'package'  => 'https://example.test/wp.zip',
            'locale'   => 'en_US',
        ]];
        $update->version_checked = (string) get_bloginfo('version');
        set_site_transient('update_core', $update);
    }

    private function seedPluginUpdate(string $folder, string $pluginFile, string $newVersion): void
    {
        $update = new \stdClass();
        $update->response = [
            $folder . '/' . $pluginFile => (object) [
                'slug' => $folder,
                'new_version' => $newVersion,
                'package' => 'https://example.test/plugin.zip',
            ],
        ];
        set_site_transient('update_plugins', $update);
    }
}
