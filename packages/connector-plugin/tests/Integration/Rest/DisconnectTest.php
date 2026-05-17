<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Activation;
use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Task 10: POST /defyn-connector/v1/disconnect (spec § 5.1).
 *
 * Dashboard-initiated tear-down. Wipes dashboard-side trust material
 * (dashboard_public_key, connected_at) and resets state to "unconfigured".
 * CRITICAL: preserves the site's own keypair (site_public_key,
 * site_private_key) per F4 reset-handler precedent — operator can
 * immediately re-handshake by generating a new code without re-activating
 * the plugin.
 *
 * @group integration
 */
final class DisconnectTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        (new ConnectorState())->reset();
        Activation::activate();
        do_action('rest_api_init');
    }

    public function testSignedDisconnectWipesDashboardKeysKeepsSiteKeypair(): void
    {
        $kp      = sodium_crypto_sign_keypair();
        $privRaw = sodium_crypto_sign_secretkey($kp);
        $pubB64  = base64_encode(sodium_crypto_sign_publickey($kp));

        $sitePubKey  = base64_encode(random_bytes(32));
        $sitePrivKey = base64_encode(random_bytes(64));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => $pubB64,
            'connected_at'         => '2026-05-17 10:00:00',
            'site_public_key'      => $sitePubKey,
            'site_private_key'     => $sitePrivKey,
        ]);

        $ts    = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canon = Signer::canonical('POST', '/defyn-connector/v1/disconnect', $ts, $nonce, '');
        $sig   = base64_encode(sodium_crypto_sign_detached($canon, $privRaw));

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/disconnect');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);

        $response = rest_do_request($request);

        self::assertSame(204, $response->get_status());

        $state = new ConnectorState();
        self::assertSame('unconfigured', $state->get('state'));
        self::assertSame('', (string) $state->get('dashboard_public_key', ''));
        self::assertSame('', (string) $state->get('connected_at', ''));
        // CRITICAL: site keypair preserved per F4 reset-handler precedent
        self::assertSame($sitePubKey, $state->get('site_public_key'));
        self::assertSame($sitePrivKey, $state->get('site_private_key'));
    }
}
