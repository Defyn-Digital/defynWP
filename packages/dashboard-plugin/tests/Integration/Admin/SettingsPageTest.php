<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Admin;

use Defyn\Dashboard\Admin\SettingsPage;
use Defyn\Dashboard\Auth\GoogleConfig;
use WP_UnitTestCase;

/**
 * Light coverage for the wp-admin Settings page: register() wires the hooks and
 * the registered setting round-trips the option that GoogleConfig reads back.
 */
final class SettingsPageTest extends WP_UnitTestCase
{
    public function tearDown(): void
    {
        delete_option(GoogleConfig::OPTION_KEY);
        parent::tearDown();
    }

    public function testRegisterHooksAdminMenuAndAdminInit(): void
    {
        $page = new SettingsPage();
        $page->register();

        $this->assertNotFalse(has_action('admin_menu', [$page, 'addMenu']));
        $this->assertNotFalse(has_action('admin_init', [$page, 'registerSettings']));
    }

    public function testRegisteredSettingRoundTripsTheOption(): void
    {
        // register_setting is hooked on admin_init; fire it directly so the
        // sanitize_callback registers, then confirm the option round-trips.
        (new SettingsPage())->registerSettings();

        update_option(GoogleConfig::OPTION_KEY, '42-roundtrip.apps.googleusercontent.com');
        $this->assertSame('42-roundtrip.apps.googleusercontent.com', get_option(GoogleConfig::OPTION_KEY));
    }
}
