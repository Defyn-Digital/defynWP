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
            throw new UnknownSlugException($slug);
        }

        $updates = get_site_transient('update_plugins');
        if (!isset($updates->response[$pluginFile])) {
            throw new NoUpdateAvailableException($slug);
        }

        $skin     = new CapturingUpgraderSkin();
        $upgrader = ($this->upgraderFactory)($skin);
        $result   = $upgrader->upgrade($pluginFile);

        if ($result === false) {
            $message = $skin->lastErrorMessage() ?? 'Plugin_Upgrader returned false without a message.';
            throw new UpgradeFailedException($message);
        }
        if (is_wp_error($result)) {
            throw new UpgradeFailedException((string) $result->get_error_message());
        }

        // Re-read the version after the upgrade. We use get_plugin_data() to parse
        // just the one plugin's header instead of rescanning every plugin in
        // wp-content/plugins/ via get_plugins(). In production this picks up the
        // new version from disk; under test the stub doesn't actually swap files,
        // so we'll see the same version back.
        $pluginData = get_plugin_data(WP_PLUGIN_DIR . '/' . $pluginFile, false, false);
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
            if (!class_exists(\Plugin_Upgrader::class)) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
            }
            return new \Plugin_Upgrader($skin);
        };
    }
}
