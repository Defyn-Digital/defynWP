<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use Defyn\Connector\Rest\PluginUpdateController;
use Defyn\Connector\Rest\ThemeUpdateController;
use Defyn\Connector\SiteInfo\PluginUpgraderService;
use Defyn\Connector\SiteInfo\ThemeUpgraderService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * P2.3 Task 6 — cross-resource lock collision regression.
 *
 * Task 5's ThemeUpdateController wraps the upgrade call in a
 * try/finally so the `defyn_connector_upgrade_in_flight` transient
 * is cleared whether the upgrade succeeds, fails, or throws. These
 * tests pin that behaviour and the 409 collision path, plus verify
 * cross-resource (theme/plugin) collisions use the shared lock:
 *
 *   - testThemeLockReleasedOnSuccess         — happy path clears the lock
 *   - testThemeLockReleasedOnFailure         — failure path clears the lock
 *   - testSecondThemeCallWhileLockHeldReturns409 — collision returns 409
 *   - testThemeBlockedByPluginInFlight       — cross-resource theme←plugin
 *   - testPluginBlockedByThemeInFlight       — cross-resource plugin←theme
 *
 * Spec: docs/superpowers/specs/2026-06-06-p2-3-themes-design.md §3.3, §13.1
 *
 * @group integration
 */
final class ThemeUpdateLockTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear all transients
        delete_site_transient('update_themes');
        delete_site_transient('update_plugins');
        delete_transient('defyn_connector_upgrade_in_flight');
        // Make absolutely sure
        wp_cache_delete('defyn_connector_upgrade_in_flight', 'transient');
        wp_cache_flush();

        $keypair = sodium_crypto_sign_keypair();
        $this->privateKeyBase64 = base64_encode(sodium_crypto_sign_secretkey($keypair));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'connected_at'         => gmdate('Y-m-d H:i:s'),
        ]);

        $stylesheet = (string) get_stylesheet();
        $this->seedThemeUpdate($stylesheet, '99.9');
    }

    protected function tearDown(): void
    {
        delete_transient('defyn_connector_upgrade_in_flight');
        delete_site_transient('update_themes');
        delete_site_transient('update_plugins');
        parent::tearDown();
    }

    public function testThemeLockReleasedOnSuccess(): void
    {
        $this->registerWithSuccessfulStub();

        $slug = (string) get_stylesheet();
        $res1 = $this->sendThemeSigned($slug);

        if ($res1->get_status() !== 200) {
            $this->fail('First request returned ' . $res1->get_status() . ' with error: ' . wp_json_encode($res1->get_data()) . ' and lock was: ' . var_export(get_transient('defyn_connector_upgrade_in_flight'), true));
        }

        $this->assertSame(200, $res1->get_status());

        // Lock must be cleared after the happy path.
        $this->assertFalse(get_transient('defyn_connector_upgrade_in_flight'));

        // Reseed the update transient; wp_clean_themes_cache() cleared it above.
        $this->seedThemeUpdate($slug, '99.9');

        // Second call lands clean (no 409 collision).
        $res2 = $this->sendThemeSigned($slug);
        $this->assertSame(200, $res2->get_status());
    }

    public function testThemeLockReleasedOnFailure(): void
    {
        $this->registerWithFailingStub();

        $slug = (string) get_stylesheet();
        $res1 = $this->sendThemeSigned($slug);
        $this->assertSame(502, $res1->get_status());

        // Even on the failure path, the finally block must clear the lock.
        $this->assertFalse(get_transient('defyn_connector_upgrade_in_flight'));
    }

    public function testSecondThemeCallWhileLockHeldReturns409(): void
    {
        // Simulate an in-flight upgrade by manually planting the lock.
        set_transient('defyn_connector_upgrade_in_flight', 'other-theme', 600);
        $this->registerWithSuccessfulStub();

        $slug = (string) get_stylesheet();
        $res = $this->sendThemeSigned($slug);

        $this->assertSame(409, $res->get_status());
        $data = $res->get_data();
        $this->assertSame('connector.upgrade_in_progress', $data['error']['code']);
        $this->assertStringContainsString('other-theme', $data['error']['message']);
    }

    public function testThemeBlockedByPluginInFlight(): void
    {
        set_transient('defyn_connector_upgrade_in_flight', 'akismet', 600);
        $this->registerWithSuccessfulStub();

        $slug = (string) get_stylesheet();
        $res = $this->sendThemeSigned($slug);

        $this->assertSame(409, $res->get_status());
        $data = $res->get_data();
        $this->assertSame('connector.upgrade_in_progress', $data['error']['code']);
        $this->assertStringContainsString('akismet', $data['error']['message']);
    }

    public function testPluginBlockedByThemeInFlight(): void
    {
        $stylesheet = (string) get_stylesheet();
        set_transient('defyn_connector_upgrade_in_flight', $stylesheet, 600);

        $this->seedPluginUpdate('hello', 'hello.php', '99.9');
        $controller = new PluginUpdateController(
            new PluginUpgraderService(static fn () => new class { public function upgrade(string $pluginFile) { return true; } })
        );
        add_action('rest_api_init', static function () use ($controller): void {
            register_rest_route('defyn-connector/v1', '/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
                'methods'             => 'POST',
                'callback'            => [$controller, 'handle'],
                'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
            ], true);
        });
        do_action('rest_api_init');

        $res = $this->sendPluginSigned('hello');

        $this->assertSame(409, $res->get_status());
        $data = $res->get_data();
        $this->assertSame('plugins.update_in_progress', $data['error']['code']);
    }

    private function registerWithSuccessfulStub(): void
    {
        $controller = new ThemeUpdateController(
            new ThemeUpgraderService(
                static fn () => new class { public function upgrade(string $stylesheet) { return true; } }
            )
        );
        add_action('rest_api_init', static function () use ($controller): void {
            register_rest_route('defyn-connector/v1', '/themes/(?P<slug>[a-z0-9-]{1,80})/update', [
                'methods'             => 'POST',
                'callback'            => [$controller, 'handle'],
                'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
            ], true);
        });
        do_action('rest_api_init');
    }

    private function registerWithFailingStub(): void
    {
        $controller = new ThemeUpdateController(
            new ThemeUpgraderService(
                static function (\Defyn\Connector\SiteInfo\CapturingUpgraderSkin $skin) {
                    $skin->error('Synthetic test failure.');
                    return new class { public function upgrade(string $stylesheet) { return false; } };
                }
            )
        );
        add_action('rest_api_init', static function () use ($controller): void {
            register_rest_route('defyn-connector/v1', '/themes/(?P<slug>[a-z0-9-]{1,80})/update', [
                'methods'             => 'POST',
                'callback'            => [$controller, 'handle'],
                'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
            ], true);
        });
        do_action('rest_api_init');
    }

    private function sendThemeSigned(string $slug): \WP_REST_Response
    {
        return $this->sendSigned('POST', '/defyn-connector/v1/themes/' . $slug . '/update');
    }

    private function sendPluginSigned(string $slug): \WP_REST_Response
    {
        return $this->sendSigned('POST', '/defyn-connector/v1/plugins/' . $slug . '/update');
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

    private function seedThemeUpdate(string $stylesheet, string $newVersion): void
    {
        $update = new \stdClass();
        $update->response = [
            $stylesheet => ['theme' => $stylesheet, 'new_version' => $newVersion, 'package' => 'https://example.test/theme.zip'],
        ];
        set_site_transient('update_themes', $update);
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
