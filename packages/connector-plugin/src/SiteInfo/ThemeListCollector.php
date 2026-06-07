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

        // Register 'Tested up to' as an extra theme header so WP_Theme::get()
        // surfaces it. Without this filter, WP_Theme only parses the standard
        // registered headers and 'TestedUpTo' always returns false.
        add_filter('extra_theme_headers', [$this, 'registerTestedUpToHeader']);
        try {
            $allThemes = wp_get_themes() ?: [];
        } finally {
            remove_filter('extra_theme_headers', [$this, 'registerTestedUpToHeader']);
        }

        $themes = [];
        foreach ($allThemes as $stylesheet => $theme) {
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

    /**
     * Callback for the 'extra_theme_headers' filter.
     * Appends 'Tested up to' so WP_Theme::get('TestedUpTo') surfaces it.
     * Must be public so WordPress can call it via the filter.
     *
     * @param string[] $headers
     * @return string[]
     */
    public function registerTestedUpToHeader(array $headers): array
    {
        $headers[] = 'Tested up to';
        return $headers;
    }
}
