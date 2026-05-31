<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Admin;

use Defyn\Connector\Activation;
use Defyn\Connector\Admin\SettingsPage;
use Defyn\Connector\Storage\ConnectorState;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class SettingsPageConnectedRenderTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        (new ConnectorState())->reset();
        Activation::activate();

        $admin = self::factory()->user->create_and_get(['role' => 'administrator']);
        wp_set_current_user($admin->ID);
    }

    public function testConnectedBranchShowsDashboardPubkeyAndTimestampNotGenerateForm(): void
    {
        $dashboardPubKey = base64_encode(str_repeat('A', 32));
        $connectedAt     = '2026-05-17 12:00:00';

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => $dashboardPubKey,
            'connected_at'         => $connectedAt,
            'site_public_key'      => base64_encode(random_bytes(32)),
        ]);

        ob_start();
        (new SettingsPage())->render();
        $html = ob_get_clean();

        // Dashboard pubkey fingerprint (first 12 chars of base64)
        $this->assertStringContainsString(substr($dashboardPubKey, 0, 12), $html);
        // Handshake timestamp
        $this->assertStringContainsString($connectedAt, $html);
        // Disconnect button
        $this->assertStringContainsString('Disconnect', $html);
        // Must NOT show the Generate form (which would clobber the connection)
        $this->assertStringNotContainsString('Generate Connection Code', $html);
    }
}
