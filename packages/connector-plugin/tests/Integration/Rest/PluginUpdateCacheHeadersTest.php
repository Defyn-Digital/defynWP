<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Rest\PluginUpdateController;
use Defyn\Connector\Rest\RestRouter;
use Defyn\Connector\SiteInfo\PluginUpgraderService;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * P2.2 Task 5 — Cache-Control: no-store regression on /plugins/{slug}/update.
 *
 * Pins two things at once:
 *   1. RestRouter::register() actually wires the new route (no test-only
 *      registration in setUp — the route must exist after a vanilla
 *      `do_action('rest_api_init')` that fires the router).
 *   2. The success response flows through the existing applyNoCacheHeaders
 *      filter and ships Cache-Control: no-store + no-cache + private,
 *      Pragma: no-cache, Expires: 0 — the same envelope P2.1's
 *      PluginsCacheHeadersTest pins for /plugins and /plugins/refresh.
 *
 * Note: rest_do_request() skips the rest_post_dispatch filter pipeline that
 * applyNoCacheHeaders is hooked on; we invoke the filter manually below.
 * (P2.1 Task 4 lesson, fix 2770cd0.)
 *
 * Spec: docs/superpowers/specs/2026-06-06-p2-2-plugin-updates-design.md §3.1, §3.4
 *
 * @group integration
 */
final class PluginUpdateCacheHeadersTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;
    private string $stubPluginDir;
    private bool   $routeWiredByRouter = false;

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
        // get_plugin_data() after a successful upgrade — needs a stub on disk.
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

        // RestRouter::register() must have wired the route during this run.
        // We add the action then fire rest_api_init so the new
        // /plugins/{slug}/update route registers via the router itself.
        add_action('rest_api_init', static function (): void {
            (new RestRouter())->register();
        });
        do_action('rest_api_init');

        // Capture whether the router actually registered the new route
        // before we override it. This is what makes this test sensitive
        // to RestRouter wiring (Task 5's first goal). Asserted in the
        // test body below.
        $routes = rest_get_server()->get_routes('defyn-connector/v1');
        $this->routeWiredByRouter = isset(
            $routes['/defyn-connector/v1/plugins/(?P<slug>[a-z0-9-]{1,80})/update']
        );

        // Override the registered route's callback with a success-stubbed
        // controller (so the upgrade never touches the real WP filesystem
        // path). The `true` 4th arg to register_rest_route replaces the
        // route registered by RestRouter::register() above.
        $controller = new PluginUpdateController(
            new PluginUpgraderService(
                static fn () => new class { public function upgrade(string $pluginFile) { return true; } }
            )
        );
        register_rest_route('defyn-connector/v1', '/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);
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

    public function testRouteIsRegisteredByRestRouter(): void
    {
        // Sensitive to RestRouter::register() — fails if the route is missing
        // from the router's single-source-of-truth registration block.
        $this->assertTrue(
            $this->routeWiredByRouter,
            'RestRouter::register() must register /plugins/{slug}/update — '
            . 'no test-only registration should be the only path to this route.'
        );
    }

    public function testSuccessResponseGetsNoStoreHeaders(): void
    {
        $request  = $this->makeSignedRequest('fake-plugin');
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());

        // rest_do_request() skips rest_post_dispatch — invoke the filter
        // ourselves to exercise the same code path production HTTP traffic
        // hits via serve_request(). (P2.1 Task 4 lesson, fix 2770cd0.)
        $filtered = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);

        $this->assertInstanceOf(WP_REST_Response::class, $filtered);

        $headers      = $filtered->get_headers();
        $cacheControl = $headers['Cache-Control'] ?? '';

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertSame('no-cache', $headers['Pragma'] ?? '');
        $this->assertSame('0', $headers['Expires'] ?? '');
    }

    private function makeSignedRequest(string $slug): WP_REST_Request
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

        return $request;
    }
}
