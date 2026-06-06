<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use Defyn\Connector\Rest\ThemeUpdateController;
use Defyn\Connector\SiteInfo\CapturingUpgraderSkin;
use Defyn\Connector\SiteInfo\ThemeUpgraderService;
use WP_REST_Request;
use WP_UnitTestCase;

final class ThemeUpdateTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_themes');
        delete_transient('defyn_connector_upgrade_in_flight');

        $keypair = sodium_crypto_sign_keypair();
        $this->privateKeyBase64 = base64_encode(sodium_crypto_sign_secretkey($keypair));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'connected_at'         => gmdate('Y-m-d H:i:s'),
        ]);

        add_action('rest_api_init', static function (): void {
            register_rest_route('defyn-connector/v1', '/themes/(?P<slug>[a-z0-9-]{1,80})/update', [
                'methods'             => 'POST',
                'callback'            => [new ThemeUpdateController(), 'handle'],
                'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
            ]);
        });
        do_action('rest_api_init');
    }

    public function testUnknownSlugReturns404(): void
    {
        $res = $this->sendSigned('definitely-not-installed');

        $this->assertSame(404, $res->get_status());
        $this->assertSame('themes.unknown_slug', $res->get_data()['error']['code']);
    }

    public function testNoUpdateAvailableReturns409(): void
    {
        $stylesheet = (string) get_stylesheet();
        $res = $this->sendSigned($stylesheet);

        $this->assertSame(409, $res->get_status());
        $this->assertSame('themes.no_update_available', $res->get_data()['error']['code']);
    }

    public function testSuccessReturns200WithExpectedShape(): void
    {
        $stylesheet = (string) get_stylesheet();
        $this->seedUpdateAvailable($stylesheet, '99.9');

        $controller = new ThemeUpdateController(
            new ThemeUpgraderService(
                fn () => new class { public function upgrade(string $stylesheet) { return true; } }
            )
        );
        register_rest_route('defyn-connector/v1', '/themes/(?P<slug>[a-z0-9-]{1,80})/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);

        $res = $this->sendSigned($stylesheet);

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame($stylesheet, $data['slug']);
        $this->assertIsString($data['previous_version']);
        $this->assertIsInt($data['server_time']);
    }

    public function testUpgradeFailureReturns502(): void
    {
        $stylesheet = (string) get_stylesheet();
        $this->seedUpdateAvailable($stylesheet, '99.9');

        $controller = new ThemeUpdateController(
            new ThemeUpgraderService(
                function (CapturingUpgraderSkin $skin) {
                    $skin->error('Could not copy file. /wp-content/upgrade/theme/index.php');
                    return new class { public function upgrade(string $stylesheet) { return false; } };
                }
            )
        );
        register_rest_route('defyn-connector/v1', '/themes/(?P<slug>[a-z0-9-]{1,80})/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);

        $res = $this->sendSigned($stylesheet);

        $this->assertSame(502, $res->get_status());
        $this->assertSame('themes.update_failed', $res->get_data()['error']['code']);
        $this->assertStringContainsString('Could not copy file', $res->get_data()['error']['message']);
    }

    public function testInvalidSlugReturns404FromRouter(): void
    {
        $request = new WP_REST_Request('POST', '/defyn-connector/v1/themes/under_score/update');
        $res = rest_do_request($request);
        $this->assertSame(404, $res->get_status());
    }

    /**
     * P2.2.1 carry-over (day 1): a Theme_Upgrader stub that echoes stray bytes
     * to STDOUT must NOT corrupt the JSON response body — the controller's
     * ob_start/ob_end_clean in `finally` absorbs everything.
     *
     * Without the buffer, the upgrader's "Installing the latest version" string
     * would prepend to the WP_REST_Response body and `json_decode` on the
     * dashboard side would fail; the dashboard would then mark a successful
     * upgrade as failed (the exact P2.2 production bug fix `7a05d48`).
     */
    public function testStdoutFromUpgraderDoesNotCorruptResponse(): void
    {
        $stylesheet = (string) get_stylesheet();
        $this->seedUpdateAvailable($stylesheet, '99.9');

        $controller = new ThemeUpdateController(
            new ThemeUpgraderService(
                fn () => new class {
                    public function upgrade(string $stylesheet) {
                        echo 'Installing the latest version...';
                        echo "\n<p>HTML noise the dashboard never sees</p>";
                        return true;
                    }
                }
            )
        );
        register_rest_route('defyn-connector/v1', '/themes/(?P<slug>[a-z0-9-]{1,80})/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);

        ob_start();
        $res = $this->sendSigned($stylesheet);
        $stray = ob_get_clean();

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame($stylesheet, $data['slug']);
        $this->assertSame('', $stray, 'controller must absorb upgrader STDOUT');
    }

    private function sendSigned(string $slug): \WP_REST_Response
    {
        $ts        = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical('POST', '/defyn-connector/v1/themes/' . $slug . '/update', $ts, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached(
            $canonical,
            base64_decode($this->privateKeyBase64)
        ));

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/themes/' . $slug . '/update');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);

        return rest_do_request($request);
    }

    private function seedUpdateAvailable(string $stylesheet, string $newVersion): void
    {
        $update = new \stdClass();
        $update->response = [
            $stylesheet => [
                'theme'       => $stylesheet,
                'new_version' => $newVersion,
                'package'     => 'https://example.test/theme.zip',
            ],
        ];
        set_site_transient('update_themes', $update);
    }
}
