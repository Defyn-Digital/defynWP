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

        // wp_get_themes() is cached. On Redis-backed hosts (e.g. Kinsta) the cache
        // is often warm before our extra_theme_headers filter fires, causing
        // WP_Theme::get('TestedUpTo') to always return false. We bypass the cache
        // by reading style.css via get_file_data() per theme directly.
        $allThemes = wp_get_themes() ?: [];

        $themes = [];
        foreach ($allThemes as $stylesheet => $theme) {
            $parent     = $theme->parent();
            $slug       = (string) $stylesheet;
            $hasUpdate  = isset($updateResponses[$slug]);
            $updateRow  = $hasUpdate ? (array) $updateResponses[$slug] : [];
            $newVersion = isset($updateRow['new_version']) ? (string) $updateRow['new_version'] : null;
            $version    = (string) $theme->get('Version');

            $themes[] = [
                'slug'             => $slug,
                'name'             => (string) $theme->get('Name'),
                'version'          => $version !== '' ? $version : null,
                'parent_slug'      => $parent ? (string) $parent->get_stylesheet() : null,
                'is_active'        => $slug === $activeStylesheet,
                'update_available' => $hasUpdate,
                'update_version'   => $hasUpdate ? $newVersion : null,
                'tested_up_to'     => $this->readTestedUpToFromTheme($theme),
            ];
        }

        usort($themes, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));

        return ['themes' => $themes];
    }

    /**
     * Read the 'Tested up to' header directly from the theme's style.css,
     * bypassing the WP object cache that wp_get_themes() relies on. Needed
     * because on Redis-backed hosts (e.g. Kinsta) the theme cache is often warm
     * before our extra_theme_headers filter fires, causing the header to be
     * silently omitted from cached results.
     */
    private function readTestedUpToFromTheme(\WP_Theme $theme): ?string
    {
        $stylesheet = $theme->get_stylesheet_directory() . '/style.css';
        if (!is_readable($stylesheet)) {
            return null;
        }
        $headers = get_file_data($stylesheet, ['TestedUpTo' => 'Tested up to'], 'theme');
        $value   = $headers['TestedUpTo'] ?? '';
        return $value !== '' ? (string) $value : null;
    }
}
