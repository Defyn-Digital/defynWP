<?php
declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

/**
 * Runs WordPress's Plugin_Upgrader on the requested slug.
 *
 * Slug resolution: WordPress identifies plugins by their main file
 * (e.g. "akismet/akismet.php"). Operators (and the dashboard) only know
 * the folder name. We map folder → main file via get_plugins().
 *
 * The upgrader factory is constructor-injected so tests can swap in a
 * stub that returns true / false / WP_Error without touching disk.
 * In production the factory returns a real \Plugin_Upgrader instance.
 */
final class PluginUpgraderService
{
    /** @var callable(CapturingUpgraderSkin): object */
    private $upgraderFactory;

    /** @var callable(string $slug, string $pluginFile, string $previousVersion): string */
    private $versionReader;

    /** @var callable(): void */
    private $transientRefresher;

    /**
     * @param callable(CapturingUpgraderSkin): object|null                      $upgraderFactory
     * @param callable(string, string, string): string|null                     $versionReader
     * @param callable(): void|null                                             $transientRefresher
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
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $pluginFile = null;
        $previousVersion = '';
        foreach (get_plugins() as $file => $data) {
            $folder = strtok($file, '/');
            if ($folder === $slug) {
                $pluginFile = $file;
                $previousVersion = (string) ($data['Version'] ?? '');
                break;
            }
        }
        if ($pluginFile === null) {
            throw new UnknownSlugException(esc_html($slug));
        }

        // Refresh the update transient BEFORE the existence check so the package
        // URL we hand the upgrader is current (robust remote tools — ManageWP,
        // MainWP, WP-CLI — all refresh before installing). A genuinely
        // up-to-date plugin still drops out of $updates->response here and 409s.
        ($this->transientRefresher)();

        $updates = get_site_transient('update_plugins');
        if (!isset($updates->response[$pluginFile])) {
            throw new NoUpdateAvailableException(esc_html($slug));
        }

        // Force WordPress to use the in-process "direct" filesystem with relaxed
        // ownership — exactly what ManageWP/MainWP/WP-CLI do. Without this, some
        // hosts' get_filesystem_method() ownership probe yields a degraded handle
        // whose writes silently no-op while the upgrader still returns success
        // (the false-success bug seen on cuscal.com: 3.5.0 → 3.5.0). Guarded with
        // function_exists so the stub-factory unit path stays a no-op.
        $forceDirect = static fn (): string => 'direct';
        $allowCreds  = static fn () => true;
        if (function_exists('add_filter')) {
            add_filter('filesystem_method', $forceDirect, 999);
            add_filter('request_filesystem_credentials', $allowCreds, 999);
        }

        try {
            $skin     = new CapturingUpgraderSkin();
            $upgrader = ($this->upgraderFactory)($skin);
            $result   = $upgrader->upgrade($pluginFile);
        } finally {
            if (function_exists('remove_filter')) {
                remove_filter('filesystem_method', $forceDirect, 999);
                remove_filter('request_filesystem_credentials', $allowCreds, 999);
            }
        }

        if ($result === false) {
            $message = $skin->lastErrorMessage() ?? 'Plugin_Upgrader returned false without a message.';
            throw new UpgradeFailedException(esc_html($message));
        }
        if (is_wp_error($result)) {
            throw new UpgradeFailedException(esc_html((string) $result->get_error_message()));
        }

        // Re-read the version after the upgrade. The default reader (below)
        // flushes WP's plugin cache, PHP's stat cache, and opcache, then parses
        // just the one plugin's header via get_plugin_data() — so it picks up the
        // freshly written version from disk. The read is injectable so tests can
        // simulate a real version bump (the stub upgrader never swaps files).
        $newVersion = ($this->versionReader)($slug, $pluginFile, $previousVersion);

        // The upgrader returned success but the version on disk did not advance —
        // the new files were not actually written (silent no-op filesystem). Fail
        // loudly with diagnostics instead of reporting a false success the
        // dashboard would trust and record as "updated".
        if (
            $newVersion === $previousVersion
            || ($newVersion !== '' && $previousVersion !== '' && version_compare($newVersion, $previousVersion, '<='))
        ) {
            $method   = function_exists('get_filesystem_method') ? (string) get_filesystem_method([], '', true) : 'unknown';
            $writable = (defined('WP_PLUGIN_DIR') && is_writable(WP_PLUGIN_DIR)) ? 'yes' : 'no';
            $skinErrs = $skin->errors();
            $detail   = $skinErrs !== [] ? implode(' | ', array_slice($skinErrs, -3)) : 'none';
            throw new UpgradeFailedException(esc_html(sprintf(
                'Upgrade reported success but version did not change (stayed %s). fs_method=%s plugins_dir_writable=%s upgrader_errors=%s',
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
            if (!class_exists(\Plugin_Upgrader::class)) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
            }
            return new \Plugin_Upgrader($skin);
        };
    }

    /**
     * Reads the plugin's version off disk AFTER the upgrade, flushing every
     * cache layer first so we never re-read a stale header (the v0.2.3 fix,
     * preserved here verbatim and made injectable for tests).
     *
     * @return callable(string $slug, string $pluginFile, string $previousVersion): string
     */
    private static function defaultVersionReader(): callable
    {
        return static function (string $slug, string $pluginFile, string $previousVersion): string {
            $fullPath = WP_PLUGIN_DIR . '/' . $pluginFile;
            if (function_exists('wp_clean_plugins_cache')) {
                wp_clean_plugins_cache(true); // clears plugin cache + the update_plugins transient so "update available" clears
            }
            clearstatcache(true, $fullPath);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($fullPath, true);
            }
            $pluginData = get_plugin_data($fullPath, false, false);
            return (string) ($pluginData['Version'] ?? $previousVersion);
        };
    }

    /** @return callable(): void */
    private static function defaultTransientRefresher(): callable
    {
        return static function (): void {
            if (function_exists('wp_update_plugins')) {
                wp_update_plugins();
            }
        };
    }
}
