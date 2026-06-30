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

    /** @var callable(string $slug, string $previousVersion): string */
    private $versionReader;

    /** @var callable(): void */
    private $transientRefresher;

    /**
     * @param callable(CapturingUpgraderSkin): object|null $upgraderFactory
     * @param callable(string, string): string|null        $versionReader
     * @param callable(): void|null                         $transientRefresher
     */
    public function __construct(
        ?callable $upgraderFactory = null,
        ?callable $versionReader = null,
        ?callable $transientRefresher = null
    ) {
        $this->upgraderFactory    = $upgraderFactory ?? self::defaultUpgraderFactory();
        $this->versionReader      = $versionReader ?? self::defaultVersionReader();
        $this->transientRefresher = $transientRefresher ?? self::defaultTransientRefresher();
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

        // Refresh the update transient BEFORE the existence check so the package
        // URL we hand the upgrader is current (mirrors ManageWP/MainWP/WP-CLI).
        // A genuinely up-to-date theme still 409s via the check below.
        ($this->transientRefresher)();

        $updates = get_site_transient('update_themes');
        if (!isset($updates->response[$slug])) {
            throw new NoThemeUpdateAvailableException(esc_html($slug));
        }

        // Do NOT force WordPress's filesystem method. Let WP resolve it exactly
        // as wp-admin / WP-CLI / ManageWP do. Forcing 'direct' (v0.2.4) made WP
        // Engine WORSE: the forced write grinds past the host's ~60s request cap
        // and the process is killed (bare 502). The version-advanced guard below
        // still catches a silent no-op without forcing anything; use
        // GET /upgrade-diagnostics to inspect the host filesystem state.
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

        // Re-read the version from disk after the upgrade. The default reader
        // (below) flushes WP's theme cache, PHP's stat cache, and opcache before
        // reading style.css, so it picks up the freshly written version. The read
        // is injectable so tests can simulate a real version bump.
        $newVersion = ($this->versionReader)($slug, $previousVersion);

        // The upgrader returned success but the version on disk did not advance —
        // the new files were not actually written. Fail loudly with diagnostics
        // instead of reporting a false success the dashboard would trust.
        if (
            $newVersion === $previousVersion
            || ($newVersion !== '' && $previousVersion !== '' && version_compare($newVersion, $previousVersion, '<='))
        ) {
            $method   = function_exists('get_filesystem_method') ? (string) get_filesystem_method([], '', true) : 'unknown';
            $themeRoot = function_exists('get_theme_root') ? get_theme_root($slug) : '';
            $writable = ($themeRoot !== '' && is_writable($themeRoot)) ? 'yes' : 'no';
            $skinErrs = $skin->errors();
            $detail   = $skinErrs !== [] ? implode(' | ', array_slice($skinErrs, -3)) : 'none';
            throw new ThemeUpgradeFailedException(esc_html(sprintf(
                'Upgrade reported success but version did not change (stayed %s). fs_method=%s themes_dir_writable=%s upgrader_errors=%s',
                $previousVersion,
                $method,
                $writable,
                $detail
            )));
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

    /**
     * Reads the theme's version off disk AFTER the upgrade, flushing every cache
     * layer first so we never re-read a stale style.css header (the v0.2.3 fix,
     * preserved here verbatim and made injectable for tests).
     *
     * @return callable(string $slug, string $previousVersion): string
     */
    private static function defaultVersionReader(): callable
    {
        return static function (string $slug, string $previousVersion): string {
            $styleSheetPath = get_theme_root($slug) . '/' . $slug . '/style.css';
            if (function_exists('wp_clean_themes_cache')) {
                wp_clean_themes_cache(); // clears theme cache + the update_themes transient so "update available" clears
            }
            clearstatcache(true, $styleSheetPath);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($styleSheetPath, true);
            }
            $newVersion = (string) wp_get_theme($slug)->get('Version');
            return $newVersion === '' ? $previousVersion : $newVersion;
        };
    }

    /** @return callable(): void */
    private static function defaultTransientRefresher(): callable
    {
        return static function (): void {
            if (function_exists('wp_update_themes')) {
                wp_update_themes();
            }
        };
    }
}
