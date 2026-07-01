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

        // v0.2.6 — WP Engine update support. WP Engine requires WordPress auth
        // cookies on a request before it will permit a filesystem-modifying
        // upgrade. The dashboard attaches those cookies to our signed update
        // request on WPE sites; but a logged-in cookie on a REST POST normally
        // trips rest_cookie_invalid_nonce (403). Our routes are gated by the
        // Ed25519 signature (permission_callback), NOT by WP cookie auth, so we
        // short-circuit the cookie/nonce auth check for our namespace only.
        // rest_cookie_check_errors returns early when a prior callback set a
        // non-null result, so returning true here skips the nonce check without
        // touching any other REST endpoint. It does NOT bypass our
        // permission_callback — bad signatures are still rejected per-route.
        add_filter('rest_authentication_errors', static function ($result) {
            if (!empty($result)) {
                return $result;
            }
            $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
            if (strpos($uri, '/' . RestRouter::NAMESPACE . '/') !== false) {
                return true;
            }
            return $result;
        }, 5);

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
