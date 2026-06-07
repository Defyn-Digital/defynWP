<?php

declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

/**
 * P2.3 — gathers the GET /themes payload (spec § 3.1).
 *
 * Pure read; never mutates WP state. Loads admin includes lazily because
 * wp_get_themes() lives in wp-admin/includes/theme.php.
 */
final class ThemeListCollector
{
    /**
     * @return array{
     *   themes: list<array{
     *     slug: string,
     *     name: string,
     *     version: ?string,
     *     parent_slug: ?string,
     *     is_active: bool,
     *     update_available: bool,
     *     update_version: ?string,
     *     tested_up_to: ?string
     *   }>
     * }
     */
    public function collect(): array
    {
        if (!function_exists('wp_get_themes')) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
        }

        $activeStylesheet = (string) get_stylesheet();
        $updates          = get_site_transient('update_themes');
        $updateResponses  = is_object($updates) && isset($updates->response)
            ? (array) $updates->response
            : [];

        $themes = [];
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $parent     = $theme->parent();
            $slug       = (string) $stylesheet;
            $hasUpdate  = isset($updateResponses[$slug]);
            $updateRow  = $hasUpdate ? (array) $updateResponses[$slug] : [];
            $newVersion = isset($updateRow['new_version']) ? (string) $updateRow['new_version'] : null;
            $version    = (string) $theme->get('Version');

            $tested = $theme->get('TestedUpTo');
            $themes[] = [
                'slug'             => $slug,
                'name'             => (string) $theme->get('Name'),
                'version'          => $version !== '' ? $version : null,
                'parent_slug'      => $parent ? (string) $parent->get_stylesheet() : null,
                'is_active'        => $slug === $activeStylesheet,
                'update_available' => $hasUpdate,
                'update_version'   => $hasUpdate ? $newVersion : null,
                'tested_up_to'     => ($tested !== false && $tested !== '') ? (string) $tested : null,
            ];
        }

        usort($themes, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));

        return ['themes' => $themes];
    }
}
