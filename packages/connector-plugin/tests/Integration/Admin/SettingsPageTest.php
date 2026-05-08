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
final class SettingsPageTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        (new ConnectorState())->reset();
        Activation::activate();

        $admin = self::factory()->user->create_and_get(['role' => 'administrator']);
        wp_set_current_user($admin->ID);

        // Set up session token for nonce verification
        $this->setUpSessionToken($admin->ID);
    }

    private function setUpSessionToken(int $user_id): void
    {
        // Create a session token for the user so that nonces can be properly verified
        $manager = \WP_Session_Tokens::get_instance($user_id);
        $token   = $manager->create(time() + 24 * 60 * 60);

        // Set the logged-in cookie
        $_COOKIE[LOGGED_IN_COOKIE] = $user_id . '|' . $token;
    }

    public function testHandleGenerateProducesCodeAndUpdatesStateToAwaitingHandshake(): void
    {
        $nonce = wp_create_nonce(SettingsPage::NONCE_GENERATE);
        $_REQUEST['_wpnonce'] = $nonce;
        $_POST['_wpnonce'] = $nonce;
        $_SERVER['HTTP_REFERER'] = admin_url('options-general.php');
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-post.php';

        $page = new SettingsPage();
        $page->handleGenerate();

        $state = (new ConnectorState())->all();
        self::assertSame('awaiting-handshake', $state['state']);
        self::assertSame(12, strlen($state['connection_code']));
        self::assertNotEmpty($state['site_nonce']);
        self::assertGreaterThan(time(), $state['code_expires_at']);
        self::assertArrayNotHasKey('code_consumed_at', $state);
    }

    public function testHandleGenerateRejectsBadNonce(): void
    {
        $_POST['_wpnonce'] = 'not-a-real-nonce';

        $page = new SettingsPage();

        $this->expectException(\WPDieException::class);
        $page->handleGenerate();
    }

    public function testHandleResetClearsCodeFieldsButPreservesKeypair(): void
    {
        // Set up an awaiting-handshake state
        (new ConnectorState())->update([
            'state'           => 'awaiting-handshake',
            'connection_code' => 'AAAAAAAAAAAA',
            'site_nonce'      => 'abc',
            'code_expires_at' => time() + 600,
        ]);
        $beforePublicKey = (new ConnectorState())->get('site_public_key');

        $nonce = wp_create_nonce(SettingsPage::NONCE_RESET);
        $_REQUEST['_wpnonce'] = $nonce;
        $_POST['_wpnonce'] = $nonce;
        $_SERVER['HTTP_REFERER'] = admin_url('options-general.php');
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-post.php';

        $page = new SettingsPage();
        $page->handleReset();

        $state = (new ConnectorState())->all();
        self::assertSame('unconfigured', $state['state']);
        self::assertArrayNotHasKey('connection_code', $state);
        self::assertArrayNotHasKey('site_nonce', $state);
        self::assertArrayNotHasKey('code_expires_at', $state);
        self::assertSame($beforePublicKey, $state['site_public_key']);
    }
}
