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
            'core'           => $this->collectCoreUpdate(),
            'server_time'    => time(),
        ];
    }

    private function detectSslStatus(): string
    {
        $siteUrl = (string) get_option('siteurl', '');
        return str_starts_with($siteUrl, 'https://') ? 'enabled' : 'disabled';
    }

    /**
     * P2.4 — read the WP `update_core` site transient and shape the SPA-visible
     * core sub-object. Pure read: never calls wp_version_check() here. The
     * transient is refreshed by WP's own cron; on-demand refresh lives in
     * POST /defyn-connector/v1/core/refresh.
     *
     * @return array{
     *   update_available: bool,
     *   update_version: ?string,
     *   is_minor_update: bool,
     *   is_auto_update_enabled: bool
     * }
     */
    private function collectCoreUpdate(): array
    {
        if (!function_exists('get_core_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        $current = (string) get_bloginfo('version');
        $updates = get_core_updates(['available' => true, 'dismissed' => false]);

        foreach ((array) $updates as $u) {
            if (!isset($u->response) || $u->response !== 'upgrade') {
                continue;
            }
            $target = (string) ($u->current ?? $u->version ?? '');
            if ($target === '') {
                continue;
            }
            return [
                'update_available'       => true,
                'update_version'         => $target,
                'is_minor_update'        => self::isMinorUpgrade($current, $target),
                'is_auto_update_enabled' => self::isMinorAutoUpdateEnabled(),
            ];
        }

        return [
            'update_available'       => false,
            'update_version'         => null,
            'is_minor_update'        => false,
            'is_auto_update_enabled' => self::isMinorAutoUpdateEnabled(),
        ];
    }

    private static function isMinorUpgrade(string $current, string $target): bool
    {
        [$cMaj, $cMin] = array_pad(array_slice(explode('.', $current), 0, 2), 2, '0');
        [$tMaj, $tMin] = array_pad(array_slice(explode('.', $target), 0, 2), 2, '0');
        return $cMaj === $tMaj && $cMin === $tMin;
    }

    private static function isMinorAutoUpdateEnabled(): bool
    {
        if (!defined('WP_AUTO_UPDATE_CORE')) {
            // WP default: minor updates enabled.
            return true;
        }
        return in_array(WP_AUTO_UPDATE_CORE, [true, 'minor', 'minor-security'], true);
    }
}
