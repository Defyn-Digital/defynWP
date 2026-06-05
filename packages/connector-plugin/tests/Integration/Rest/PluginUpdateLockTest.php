<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Rest\PluginUpdateController;
use Defyn\Connector\SiteInfo\PluginUpgraderService;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * P2.2 Task 4 — per-site transient lock regression.
 *
 * Task 3's PluginUpdateController wraps the upgrade call in a
 * try/finally so the `defyn_connector_upgrade_in_flight` transient
 * is cleared whether the upgrade succeeds, fails, or throws. These
 * tests pin that behaviour and the 409 collision path:
 *
 *   - testLockReleasedOnSuccess        — happy path clears the lock
 *   - testLockReleasedOnFailure        — failure path clears the lock
 *   - testSecondCallWhileLockHeldReturns409 — collision returns 409
 *
 * Spec: docs/superpowers/specs/2026-06-06-p2-2-plugin-updates-design.md §3.2, §13.1
 *
 * @group integration
 */
final class PluginUpdateLockTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;
    private string $stubPluginDir;

    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_plugins');
        delete_transient('defyn_connector_upgrade_in_flight');

        $keypair = sodium_crypto_sign_keypair();
        $this->privateKeyBase64 = base64_encode(sodium_crypto_sign_secretkey($keypair));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'connected_at'         => gmdate('Y-m-d H:i:s'),
        ]);

        // wp-phpunit gotcha #1: inject synthetic plugin into get_plugins() cache.
        // get_plugins() short-circuits on wp_cache_get('plugins', 'plugins')
        // and does NOT apply an `all_plugins` filter; cache injection is the
        // only reliable mechanism for synthetic plugins.
        wp_cache_set('plugins', ['' => [
            'fake-plugin/fake-plugin.php' => [
                'Name'        => 'Fake',
                'Version'     => '1.0.0',
                'PluginURI'   => '',
                'AuthorURI'   => '',
                'Description' => '',
                'Author'      => '',
                'Title'       => 'Fake',
                'AuthorName'  => '',
            ],
        ]], 'plugins');

        // wp-phpunit gotcha #2: PluginUpgraderService::upgrade() calls
        // get_plugin_data() after a successful upgrade to re-read the
        // version. That goes straight to disk, so we need a stub file.
        $this->stubPluginDir = WP_PLUGIN_DIR . '/fake-plugin';
        if (!is_dir($this->stubPluginDir)) {
            mkdir($this->stubPluginDir, 0777, true);
        }
        file_put_contents(
            $this->stubPluginDir . '/fake-plugin.php',
            "<?php\n/*\nPlugin Name: Fake\nVersion: 1.0.0\n*/\n"
        );

        // Seed the update_plugins transient so the service finds an update.
        $update = new \stdClass();
        $update->response = [
            'fake-plugin/fake-plugin.php' => (object) [
                'slug'        => 'fake-plugin',
                'new_version' => '2.0.0',
                'package'     => 'https://example.test/plugin.zip',
            ],
        ];
        set_site_transient('update_plugins', $update);
    }

    protected function tearDown(): void
    {
        $file = $this->stubPluginDir . '/fake-plugin.php';
        if (file_exists($file)) {
            unlink($file);
        }
        if (is_dir($this->stubPluginDir)) {
            rmdir($this->stubPluginDir);
        }
        wp_cache_delete('plugins', 'plugins');
        delete_transient('defyn_connector_upgrade_in_flight');
        parent::tearDown();
    }

    public function testLockReleasedOnSuccess(): void
    {
        $this->registerWithSuccessfulStub();

        $res1 = $this->sendSigned('fake-plugin');
        $this->assertSame(200, $res1->get_status());

        // Lock must be cleared after the happy path.
        $this->assertFalse(get_transient('defyn_connector_upgrade_in_flight'));

        // Second call lands clean (no 409 collision).
        $res2 = $this->sendSigned('fake-plugin');
        $this->assertSame(200, $res2->get_status());
    }

    public function testLockReleasedOnFailure(): void
    {
        $this->registerWithFailingStub();

        $res1 = $this->sendSigned('fake-plugin');
        $this->assertSame(502, $res1->get_status());

        // Even on the failure path, the finally block must clear the lock.
        $this->assertFalse(get_transient('defyn_connector_upgrade_in_flight'));
    }

    public function testSecondCallWhileLockHeldReturns409(): void
    {
        // Simulate an in-flight upgrade by manually planting the lock.
        set_transient('defyn_connector_upgrade_in_flight', 'other-plugin', 600);
        $this->registerWithSuccessfulStub();

        $res = $this->sendSigned('fake-plugin');

        $this->assertSame(409, $res->get_status());
        $data = $res->get_data();
        $this->assertSame('plugins.update_in_progress', $data['error']['code']);
        $this->assertStringContainsString('other-plugin', $data['error']['message']);
    }

    private function registerWithSuccessfulStub(): void
    {
        $controller = new PluginUpdateController(
            new PluginUpgraderService(
                static fn () => new class { public function upgrade(string $pluginFile) { return true; } }
            )
        );
        add_action('rest_api_init', static function () use ($controller): void {
            register_rest_route('defyn-connector/v1', '/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
                'methods'             => 'POST',
                'callback'            => [$controller, 'handle'],
                'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
            ], true);
        });
        do_action('rest_api_init');
    }

    private function registerWithFailingStub(): void
    {
        $controller = new PluginUpdateController(
            new PluginUpgraderService(
                static function (\Defyn\Connector\SiteInfo\CapturingUpgraderSkin $skin) {
                    $skin->error('Synthetic test failure.');
                    return new class { public function upgrade(string $pluginFile) { return false; } };
                }
            )
        );
        add_action('rest_api_init', static function () use ($controller): void {
            register_rest_route('defyn-connector/v1', '/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
                'methods'             => 'POST',
                'callback'            => [$controller, 'handle'],
                'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
            ], true);
        });
        do_action('rest_api_init');
    }

    private function sendSigned(string $slug): \WP_REST_Response
    {
        $ts        = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical(
            'POST',
            '/defyn-connector/v1/plugins/' . $slug . '/update',
            $ts,
            $nonce,
            ''
        );
        $sig = base64_encode(sodium_crypto_sign_detached(
            $canonical,
            base64_decode($this->privateKeyBase64)
        ));

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/plugins/' . $slug . '/update');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);

        return rest_do_request($request);
    }
}
