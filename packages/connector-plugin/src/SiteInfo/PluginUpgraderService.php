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

        $updates = get_site_transient('update_plugins');
        if (!isset($updates->response[$pluginFile])) {
            throw new NoUpdateAvailableException(esc_html($slug));
        }

        $skin     = new CapturingUpgraderSkin();
        $upgrader = ($this->upgraderFactory)($skin);
        $result   = $upgrader->upgrade($pluginFile);

        if ($result === false) {
            $message = $skin->lastErrorMessage() ?? 'Plugin_Upgrader returned false without a message.';
            throw new UpgradeFailedException(esc_html($message));
        }
        if (is_wp_error($result)) {
            throw new UpgradeFailedException(esc_html((string) $result->get_error_message()));
        }

        // Re-read the version after the upgrade. We use get_plugin_data() to parse
        // just the one plugin's header instead of rescanning every plugin in
        // wp-content/plugins/ via get_plugins(). In production this picks up the
        // new version from disk; under test the stub doesn't actually swap files,
        // so we'll see the same version back.
        //
        // BUT: Plugin_Upgrader::upgrade() has just rewritten the plugin's files,
        // and WordPress (plus PHP's stat cache and opcache) may still be holding
        // the OLD header in memory. Without flushing those caches, get_plugin_data()
        // re-reads the stale version (e.g. reports 3.5.0 after a real 3.5.0→3.5.1
        // upgrade) and the site keeps showing "update available". Refresh all three
        // caches before the re-read. Calls are function_exists-guarded so the unit
        // tests (which inject a stub factory and run without a full WP) stay no-ops.
        $fullPath = WP_PLUGIN_DIR . '/' . $pluginFile;
        if (function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true); // clears plugin cache + the update_plugins transient so "update available" clears
        }
        clearstatcache(true, $fullPath);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($fullPath, true);
        }
        $pluginData = get_plugin_data($fullPath, false, false);
        $newVersion = (string) ($pluginData['Version'] ?? $previousVersion);

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
}
