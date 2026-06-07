<?php

declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

/**
 * P2.1 — gathers the GET /plugins payload (spec § 3.1 + § 4.1).
 *
 * Pure read; never mutates WP state. Loads admin includes lazily because
 * get_plugins() lives in wp-admin/includes/plugin.php.
 */
final class PluginListCollector
{
    public const MAX_PLUGINS = 500;

    /**
     * @return array{
     *   plugins: list<array{
     *     slug: string,
     *     name: string,
     *     version: ?string,
     *     update_available: bool,
     *     update_version: ?string,
     *     tested_up_to: ?string
     *   }>,
     *   truncated: bool
     * }
     */
    public function collect(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all     = get_plugins() ?: [];
        $updates = get_site_transient('update_plugins');
        $byPath  = is_object($updates) && isset($updates->response)
            ? (array) $updates->response
            : [];

        $plugins = [];
        foreach ($all as $slug => $header) {
            $name = (string) ($header['Name'] ?? '');
            if ($name === '') {
                continue;
            }
            $version = (string) ($header['Version'] ?? '');
            $upd     = $byPath[(string) $slug] ?? null;
            $plugins[] = [
                'slug'             => (string) $slug,
                'name'             => $name,
                'version'          => $version !== '' ? $version : null,
                'update_available' => $upd !== null,
                'update_version'   => $upd !== null && isset($upd->new_version)
                    ? (string) $upd->new_version
                    : null,
                'tested_up_to'     => !empty($header['Tested up to'])
                    ? (string) $header['Tested up to']
                    : null,
            ];
        }

        usort($plugins, static fn(array $a, array $b): int => strcmp($a['slug'], $b['slug']));

        $truncated = count($plugins) > self::MAX_PLUGINS;
        if ($truncated) {
            $plugins = array_slice($plugins, 0, self::MAX_PLUGINS);
        }

        return [
            'plugins'   => $plugins,
            'truncated' => $truncated,
        ];
    }
}
