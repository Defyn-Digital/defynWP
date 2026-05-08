<?php

declare(strict_types=1);

namespace Defyn\Connector;

use Defyn\Connector\Admin\SettingsPage;
use Defyn\Connector\Rest\RestRouter;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function boot(): void
    {
        register_activation_hook(DEFYN_CONNECTOR_FILE, [Activation::class, 'activate']);

        add_action('rest_api_init', static function (): void {
            (new RestRouter())->register();
        });

        add_action('admin_menu', static function (): void {
            (new SettingsPage())->registerMenu();
        });

        add_action('admin_post_' . SettingsPage::ACTION_GENERATE, static function (): void {
            (new SettingsPage())->handleGenerate();
        });

        add_action('admin_post_' . SettingsPage::ACTION_RESET, static function (): void {
            (new SettingsPage())->handleReset();
        });
    }
}
