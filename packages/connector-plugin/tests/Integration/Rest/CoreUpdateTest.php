<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use Defyn\Connector\Rest\CoreUpdateController;
use Defyn\Connector\SiteInfo\CapturingUpgraderSkin;
use Defyn\Connector\SiteInfo\CoreUpgraderService;
use WP_REST_Request;
use WP_UnitTestCase;

final class CoreUpdateTest extends WP_UnitTestCase
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
            register_rest_route('defyn-connector/v1', '/core/update', [
                'methods'             => 'POST',
                'callback'            => [new CoreUpdateController(), 'handle'],
                'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
            ]);
        });
        do_action('rest_api_init');
    }

    public function testNoUpdateAvailableReturns409(): void
    {
        $res = $this->sendSigned();

        $this->assertSame(409, $res->get_status());
        $this->assertSame('core.no_update_available', $res->get_data()['error']['code']);
    }

    public function testMajorBumpReturns409(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj] = explode('.', $current) + [0 => '0'];
        $target = ((int) $maj + 1) . '.0';
        $this->seedUpdateAvailable($target);

        $res = $this->sendSigned();

        $this->assertSame(409, $res->get_status());
        $this->assertSame('core.major_update_blocked', $res->get_data()['error']['code']);
        $this->assertStringContainsString($target, $res->get_data()['error']['message']);
    }

    public function testSuccessReturns200WithExpectedShape(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $target = $maj . '.' . $min . '.1';
        $this->seedUpdateAvailable($target);

        $controller = new CoreUpdateController(
            new CoreUpgraderService(
                fn () => new class { public function upgrade($update) { return true; } }
            )
        );
        register_rest_route('defyn-connector/v1', '/core/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);

        $res = $this->sendSigned();

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertTrue($data['success']);
        $this->assertIsString($data['previous_version']);
        $this->assertIsString($data['new_version']);
        $this->assertIsInt($data['server_time']);
    }

    public function testUpgradeFailureReturns502(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $target = $maj . '.' . $min . '.1';
        $this->seedUpdateAvailable($target);

        $controller = new CoreUpdateController(
            new CoreUpgraderService(
                function (CapturingUpgraderSkin $skin) {
                    $skin->error('Could not copy file. /wp-admin/index.php');
                    return new class { public function upgrade($update) { return false; } };
                }
            )
        );
        register_rest_route('defyn-connector/v1', '/core/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);

        $res = $this->sendSigned();

        $this->assertSame(502, $res->get_status());
        $this->assertSame('core.update_failed', $res->get_data()['error']['code']);
        $this->assertStringContainsString('Could not copy file', $res->get_data()['error']['message']);
    }

    public function testInvalidPathReturns404FromRouter(): void
    {
        $request = new WP_REST_Request('POST', '/defyn-connector/v1/core/under_score');
        $res = rest_do_request($request);
        $this->assertSame(404, $res->get_status());
    }

    public function testStdoutFromUpgraderDoesNotCorruptResponse(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $target = $maj . '.' . $min . '.1';
        $this->seedUpdateAvailable($target);

        $controller = new CoreUpdateController(
            new CoreUpgraderService(
                fn () => new class {
                    public function upgrade($update) {
                        echo 'Updating WordPress to ' . ($update->current ?? '?') . '...';
                        echo "\n<p>HTML noise the dashboard never sees</p>";
                        return true;
                    }
                }
            )
        );
        register_rest_route('defyn-connector/v1', '/core/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);

        ob_start();
        $res = $this->sendSigned();
        $stray = ob_get_clean();

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame('', $stray, 'controller must absorb upgrader STDOUT');
    }

    private function sendSigned(): \WP_REST_Response
    {
        $ts        = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical('POST', '/defyn-connector/v1/core/update', $ts, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached(
            $canonical,
            base64_decode($this->privateKeyBase64)
        ));

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/core/update');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);

        return rest_do_request($request);
    }

    private function seedUpdateAvailable(string $target): void
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
}
