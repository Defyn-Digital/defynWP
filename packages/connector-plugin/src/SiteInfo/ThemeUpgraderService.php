<?php

declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

/**
 * Runs WordPress's Theme_Upgrader on the requested stylesheet (slug).
 *
 * Slug resolution: WordPress identifies themes by their stylesheet directory
 * name (e.g. "twentytwentyfive"). The slug the dashboard sends IS the
 * stylesheet — no plugin_file-shape mismatch like P2.2 had to handle.
 *
 * The upgrader factory is constructor-injected so tests can swap in a
 * stub that returns true / false / WP_Error without touching disk.
 * In production the factory returns a real \Theme_Upgrader instance.
 */
final class ThemeUpgraderService
{
    /** @var callable(CapturingUpgraderSkin): object */
    private $upgraderFactory;

    /**
     * @param callable(CapturingUpgraderSkin): object|null $upgraderFactory
     */
    public function __construct(?callable $upgraderFactory = null)
    {
        $this->upgraderFactory = $upgraderFactory ?? self::defaultUpgraderFactory();
    }

    /**
     * @return array{success: true, slug: string, previous_version: string, new_version: string, server_time: int}
     */
    public function upgrade(string $slug): array
    {
        if (!function_exists('wp_get_themes')) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
        }

        $themes = wp_get_themes();
        if (!isset($themes[$slug])) {
            throw new UnknownThemeSlugException(esc_html($slug));
        }
        $previousVersion = (string) $themes[$slug]->get('Version');

        $updates = get_site_transient('update_themes');
        if (!isset($updates->response[$slug])) {
            throw new NoThemeUpdateAvailableException(esc_html($slug));
        }

        $skin     = new CapturingUpgraderSkin();
        $upgrader = ($this->upgraderFactory)($skin);
        $result   = $upgrader->upgrade($slug);

        if ($result === false) {
            $message = $skin->lastErrorMessage() ?? 'Theme_Upgrader returned false without a message.';
            throw new ThemeUpgradeFailedException(esc_html($message));
        }
        if (is_wp_error($result)) {
            throw new ThemeUpgradeFailedException(esc_html((string) $result->get_error_message()));
        }

        // Re-read the version from disk after the upgrade. In production this
        // picks up the new version; under test the stub didn't swap files, so
        // we'll see the same version back.
        //
        // Theme_Upgrader::upgrade() has just rewritten the theme's files, but
        // WordPress's theme cache, PHP's stat cache, and opcache may still hold
        // the OLD style.css header — so wp_get_theme() would report the stale
        // version and the site would keep showing "update available". Flush all
        // three before the re-read. Calls are function_exists-guarded so the
        // unit tests (stub factory, no full WP) stay no-ops.
        $styleSheetPath = get_theme_root($slug) . '/' . $slug . '/style.css';
        if (function_exists('wp_clean_themes_cache')) {
            wp_clean_themes_cache(); // clears theme cache + the update_themes transient so "update available" clears
        }
        clearstatcache(true, $styleSheetPath);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($styleSheetPath, true);
        }
        $newVersion = (string) wp_get_theme($slug)->get('Version');
        if ($newVersion === '') {
            $newVersion = $previousVersion;
        }

        return [
            'success'          => true,
            'slug'             => $slug,
            'previous_version' => $previousVersion,
            'new_version'      => $newVersion,
            'server_time'      => time(),
        ];
    }

    /** @return callable(CapturingUpgraderSkin): object */
    private static function defaultUpgraderFactory(): callable
    {
        return static function (CapturingUpgraderSkin $skin): object {
            // WP_Upgrader::run() -> fs_connect() calls WP_Filesystem(), which is
            // defined in wp-admin/includes/file.php — NOT autoloaded in the REST
            // request context. Without it the upgrade fatals with
            // "Call to undefined function WP_Filesystem()" and the controller
            // returns a bare HTTP 500. Load it (and misc.php for show_message())
            // before constructing the upgrader, exactly like wp-admin does.
            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            if (!function_exists('show_message')) {
                require_once ABSPATH . 'wp-admin/includes/misc.php';
            }
            if (!class_exists(\Theme_Upgrader::class)) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
            }
            return new \Theme_Upgrader($skin);
        };
    }
}
