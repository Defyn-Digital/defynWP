<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Rest\PluginUpdateController;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * P2.2 Task 3 — POST /defyn-connector/v1/plugins/{slug}/update.
 *
 * Exercises the controller's happy path and the three exception-to-envelope
 * mappings (404 unknown_slug, 409 no_update_available, 502 update_failed).
 * The lock-collision case lives in PluginUpdateLockTest (Task 4).
 *
 * Route registration here is direct (not via RestRouter); Task 5 moves it
 * into RestRouter::register() and adds the cache-headers regression there.
 *
 * @group integration
 */
final class PluginUpdateTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;
    private string $publicKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_plugins');
        delete_transient('defyn_connector_upgrade_in_flight');

        // Generate an Ed25519 keypair and set the connector state to "connected"
        // so VerifySignatureMiddleware doesn't short-circuit.
        $keypair = sodium_crypto_sign_keypair();
        $secret  = sodium_crypto_sign_secretkey($keypair);
        $public  = sodium_crypto_sign_publickey($keypair);
        $this->privateKeyBase64 = base64_encode($secret);
        $this->publicKeyBase64  = base64_encode($public);

        (new ConnectorState())->update([
            'state'                 => 'connected',
            'dashboard_public_key'  => $this->publicKeyBase64,
            'connected_at'          => gmdate('Y-m-d H:i:s'),
        ]);

        // Register the route under test (will be moved to RestRouter in Task 5).
        // WP requires register_rest_route() be called during rest_api_init, so
        // we hook a callback then fire the action. Individual tests that want to
        // swap in a stub service do so by re-registering with override=true
        // before sending the request.
        \add_action('rest_api_init', static function (): void {
            register_rest_route('defyn-connector/v1', '/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
                'methods'             => 'POST',
                'callback'            => [new PluginUpdateController(), 'handle'],
                'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
            ]);
        });
        do_action('rest_api_init');
    }

    public function testUnknownSlugReturns404(): void
    {
        $this->seedUpdateAvailable('hello.php', '1.8.0');
        $res = $this->sendSigned('definitely-not-installed');

        $this->assertSame(404, $res->get_status());
        $this->assertSame('plugins.unknown_slug', $res->get_data()['error']['code']);
    }

    public function testNoUpdateAvailableReturns409(): void
    {
        // Register a fake plugin in-process via the `all_plugins` filter (which
        // get_plugins() respects) so the controller resolves the slug to a
        // pluginFile but the update_plugins transient stays empty.
        $this->seedFakePlugin();

        $res = $this->sendSigned('fake-plugin');

        $this->assertSame(409, $res->get_status());
        $this->assertSame('plugins.no_update_available', $res->get_data()['error']['code']);
    }

    public function testSuccessReturns200WithExpectedShape(): void
    {
        $this->seedFakePlugin();
        $this->seedUpdateAvailable('fake-plugin/fake-plugin.php', '2.0.0');

        // Swap the controller's service for one whose upgrader stub returns true.
        $controller = new PluginUpdateController(
            new \Defyn\Connector\SiteInfo\PluginUpgraderService(
                static fn () => new class { public function upgrade(string $pluginFile) { return true; } }
            )
        );
        register_rest_route('defyn-connector/v1', '/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);

        $res = $this->sendSigned('fake-plugin');

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame('fake-plugin', $data['slug']);
        $this->assertSame('1.0.0', $data['previous_version']);
        $this->assertIsInt($data['server_time']);
    }

    public function testUpgradeFailureReturns502(): void
    {
        $this->seedFakePlugin();
        $this->seedUpdateAvailable('fake-plugin/fake-plugin.php', '2.0.0');

        $controller = new PluginUpdateController(
            new \Defyn\Connector\SiteInfo\PluginUpgraderService(
                static function (\Defyn\Connector\SiteInfo\CapturingUpgraderSkin $skin) {
                    $skin->error('Could not copy file. /wp-content/upgrade/fake-plugin/fake-plugin.php');
                    return new class { public function upgrade(string $pluginFile) { return false; } };
                }
            )
        );
        register_rest_route('defyn-connector/v1', '/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);

        $res = $this->sendSigned('fake-plugin');

        $this->assertSame(502, $res->get_status());
        $this->assertSame('plugins.update_failed', $res->get_data()['error']['code']);
        $this->assertStringContainsString('Could not copy file', $res->get_data()['error']['message']);
    }

    public function testInvalidSlugReturns404FromRouter(): void
    {
        // Invalid char in slug → WP router rejects before reaching the controller
        // (rest_no_route → 404). Use underscore: outside [a-z0-9-] AND WP's
        // route regex matches case-insensitively (see wp-includes/rest-api.php
        // line 825: preg_match('@^...$@i', ...)) so uppercase letters wouldn't
        // actually fail the match. Underscore genuinely doesn't.
        $request = new WP_REST_Request('POST', '/defyn-connector/v1/plugins/under_score/update');
        $res = rest_do_request($request);
        $this->assertSame(404, $res->get_status());
    }

    private function sendSigned(string $slug): \WP_REST_Response
    {
        $ts        = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical('POST', '/defyn-connector/v1/plugins/' . $slug . '/update', $ts, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached(
            $canonical,
            base64_decode($this->privateKeyBase64)
        ));

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/plugins/' . $slug . '/update');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);

        return rest_do_request($request);
    }

    /**
     * Inject a synthetic plugin into get_plugins()'s cache so the service
     * resolves slug "fake-plugin" without touching the disk for resolution.
     *
     * `get_plugins()` short-circuits when wp_cache_get('plugins', 'plugins')
     * has the requested $plugin_folder key (empty string for the root). It
     * does NOT apply an `all_plugins` filter, so cache injection is the
     * only mechanism that works for synthetic plugins.
     *
     * Also writes a real plugin file on disk because, after a successful
     * upgrade, PluginUpgraderService::upgrade() calls get_plugin_data() to
     * re-read the version — and get_plugin_data() goes straight to disk via
     * file_get_contents(), bypassing the cache. The on-disk header is
     * deliberately the same '1.0.0' as the cache entry so previous_version
     * and new_version come back equal in the success-path test.
     */
    private function seedFakePlugin(): void
    {
        $existing = (array) (wp_cache_get('plugins', 'plugins') ?: []);
        $rootList = (array) ($existing[''] ?? []);
        $rootList['fake-plugin/fake-plugin.php'] = [
            'Name'        => 'Fake',
            'Version'     => '1.0.0',
            'PluginURI'   => '',
            'AuthorURI'   => '',
            'Description' => '',
            'Author'      => '',
            'Title'       => 'Fake',
            'AuthorName'  => '',
        ];
        $existing[''] = $rootList;
        wp_cache_set('plugins', $existing, 'plugins');

        $dir  = WP_PLUGIN_DIR . '/fake-plugin';
        $file = $dir . '/fake-plugin.php';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($file, "<?php\n/*\nPlugin Name: Fake\nVersion: 1.0.0\n*/\n");
    }

    protected function tearDown(): void
    {
        $file = WP_PLUGIN_DIR . '/fake-plugin/fake-plugin.php';
        $dir  = WP_PLUGIN_DIR . '/fake-plugin';
        if (file_exists($file)) {
            unlink($file);
        }
        if (is_dir($dir)) {
            rmdir($dir);
        }
        wp_cache_delete('plugins', 'plugins');
        parent::tearDown();
    }

    /**
     * Stand up the update_plugins transient shape WP expects so
     * isset($updates->response[$pluginFile]) is true.
     */
    private function seedUpdateAvailable(string $pluginFile, string $newVersion): void
    {
        $update = new \stdClass();
        $update->response = [
            $pluginFile => (object) [
                'slug'        => strtok($pluginFile, '/'),
                'new_version' => $newVersion,
                'package'     => 'https://example.test/plugin.zip',
            ],
        ];
        set_site_transient('update_plugins', $update);
    }
}
