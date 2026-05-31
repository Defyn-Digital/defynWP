<?php

declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

/**
 * Gathers the /status payload per spec § 5.1.
 *
 * Pure-read; never mutates WP state. Loads admin includes only when needed for
 * plugin enumeration (get_plugins() lives in wp-admin/includes/plugin.php).
 */
final class Collector
{
    /**
     * @return array{
     *   wp_version: string,
     *   php_version: string,
     *   active_theme: array{name: string, version: string, parent: ?string},
     *   plugin_counts: array{installed: int, active: int},
     *   theme_counts: array{installed: int, active: int},
     *   ssl_status: string,
     *   ssl_expires_at: ?int,
     *   server_time: int
     * }
     */
    public function collect(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $theme  = wp_get_theme();
        $parent = $theme->parent();

        $allPlugins    = get_plugins();
        $activePlugins = (array) get_option('active_plugins', []);
        if (is_multisite() && function_exists('get_site_option')) {
            $networkActive = array_keys((array) get_site_option('active_sitewide_plugins', []));
            $activePlugins = array_unique(array_merge($activePlugins, $networkActive));
        }

        $allThemes = wp_get_themes();

        return [
            'wp_version'   => (string) get_bloginfo('version'),
            'php_version'  => phpversion(),
            'active_theme' => [
                'name'    => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
                'parent'  => $parent ? (string) $parent->get('Name') : null,
            ],
            'plugin_counts' => [
                'installed' => count($allPlugins),
                'active'    => count($activePlugins),
            ],
            'theme_counts' => [
                'installed' => count($allThemes),
                'active'    => 1,
            ],
            'ssl_status'     => $this->detectSslStatus(),
            'ssl_expires_at' => null,  // Cert-expiry parsing deferred to later phase
            'server_time'    => time(),
        ];
    }

    private function detectSslStatus(): string
    {
        $siteUrl = (string) get_option('siteurl', '');
        return str_starts_with($siteUrl, 'https://') ? 'enabled' : 'disabled';
    }
}
