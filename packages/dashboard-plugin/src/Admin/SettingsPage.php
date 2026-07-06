<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Admin;

use Defyn\Dashboard\Auth\GoogleConfig;

/**
 * Settings → DefynWP options page. Exposes a single self-service field for the
 * Google OAuth Client ID so operators on hosts without an env-var UI (e.g.
 * Kinsta Managed WordPress) can configure Sign-in-with-Google from wp-admin —
 * no SSH / no env constant required.
 *
 * The value is stored in the `defyn_google_client_id` option
 * (GoogleConfig::OPTION_KEY) and read back by GoogleConfig::clientId() (which
 * still prefers the DEFYN_GOOGLE_CLIENT_ID env constant when it is set).
 */
final class SettingsPage
{
    private const MENU_SLUG = 'defyn-dashboard-settings';
    private const SETTINGS_GROUP = 'defyn_dashboard_settings';
    private const SECTION_ID = 'defyn_dashboard_google_section';
    private const PAGESPEED_OPTION = 'defyn_pagespeed_api_key';
    private const PAGESPEED_SECTION_ID = 'defyn_dashboard_pagespeed_section';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function addMenu(): void
    {
        add_options_page(
            __('DefynWP', 'defyn-dashboard'),
            __('DefynWP', 'defyn-dashboard'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        register_setting(self::SETTINGS_GROUP, GoogleConfig::OPTION_KEY, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        add_settings_section(
            self::SECTION_ID,
            __('Google Sign-in', 'defyn-dashboard'),
            [$this, 'renderSectionIntro'],
            self::MENU_SLUG
        );

        add_settings_field(
            GoogleConfig::OPTION_KEY,
            __('Google OAuth Client ID', 'defyn-dashboard'),
            [$this, 'renderClientIdField'],
            self::MENU_SLUG,
            self::SECTION_ID
        );

        // Performance — PageSpeed Insights API key (powers the weekly + on-demand
        // performance scans). Stored in `defyn_pagespeed_api_key`, read back by
        // PageSpeedClient (which still prefers the DEFYN_PAGESPEED_API_KEY env
        // constant when set). Lets operators on Kinsta Managed WordPress (no
        // env-var UI) configure it from wp-admin.
        register_setting(self::SETTINGS_GROUP, self::PAGESPEED_OPTION, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        add_settings_section(
            self::PAGESPEED_SECTION_ID,
            __('Performance (PageSpeed)', 'defyn-dashboard'),
            [$this, 'renderPagespeedSectionIntro'],
            self::MENU_SLUG
        );

        add_settings_field(
            self::PAGESPEED_OPTION,
            __('PageSpeed Insights API key', 'defyn-dashboard'),
            [$this, 'renderPagespeedField'],
            self::MENU_SLUG,
            self::PAGESPEED_SECTION_ID
        );
    }

    public function renderPagespeedSectionIntro(): void
    {
        echo '<p>' . esc_html__(
            'Powers the weekly and on-demand performance scores. Create a free key in Google Cloud Console (enable the "PageSpeed Insights API", then create an API key) and paste it here. Without a key, performance stays "Not yet measured".',
            'defyn-dashboard'
        ) . '</p>';
    }

    public function renderPagespeedField(): void
    {
        $value = (string) get_option(self::PAGESPEED_OPTION, '');
        $envOverride = defined('DEFYN_PAGESPEED_API_KEY')
            && (string) constant('DEFYN_PAGESPEED_API_KEY') !== '';

        printf(
            '<input type="password" class="regular-text" name="%s" id="%s" value="%s" autocomplete="off" />',
            esc_attr(self::PAGESPEED_OPTION),
            esc_attr(self::PAGESPEED_OPTION),
            esc_attr($value)
        );
        echo '<p class="description">' . esc_html__(
            'Google API key for PageSpeed Insights (starts with "AIza"). Stored on this server; used by the performance scans.',
            'defyn-dashboard'
        ) . '</p>';

        if ($envOverride) {
            echo '<p class="description"><strong>' . esc_html__(
                'A DEFYN_PAGESPEED_API_KEY environment constant is set and takes precedence over this field.',
                'defyn-dashboard'
            ) . '</strong></p>';
        }
    }

    public function renderSectionIntro(): void
    {
        echo '<p>' . esc_html__(
            'Configure Sign in with Google for the DefynWP operator dashboard.',
            'defyn-dashboard'
        ) . '</p>';
    }

    public function renderClientIdField(): void
    {
        $value = (string) get_option(GoogleConfig::OPTION_KEY, '');
        $envOverride = defined('DEFYN_GOOGLE_CLIENT_ID')
            && (string) constant('DEFYN_GOOGLE_CLIENT_ID') !== '';

        printf(
            '<input type="text" class="regular-text" name="%s" id="%s" value="%s" autocomplete="off" />',
            esc_attr(GoogleConfig::OPTION_KEY),
            esc_attr(GoogleConfig::OPTION_KEY),
            esc_attr($value)
        );
        echo '<p class="description">' . esc_html__(
            'Paste the OAuth Client ID from Google Cloud (ends in .apps.googleusercontent.com). Used for Sign in with Google.',
            'defyn-dashboard'
        ) . '</p>';

        if ($envOverride) {
            echo '<p class="description"><strong>' . esc_html__(
                'A DEFYN_GOOGLE_CLIENT_ID environment constant is set and takes precedence over this field.',
                'defyn-dashboard'
            ) . '</strong></p>';
        }
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('DefynWP Dashboard', 'defyn-dashboard') . '</h1>';
        echo '<form action="options.php" method="post">';
        settings_fields(self::SETTINGS_GROUP);
        do_settings_sections(self::MENU_SLUG);
        submit_button();
        echo '</form>';
        echo '</div>';
    }
}
