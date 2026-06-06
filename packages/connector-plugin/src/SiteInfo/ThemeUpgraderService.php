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
            throw new UnknownThemeSlugException($slug);
        }
        $previousVersion = (string) $themes[$slug]->get('Version');

        $updates = get_site_transient('update_themes');
        if (!isset($updates->response[$slug])) {
            throw new NoThemeUpdateAvailableException($slug);
        }

        $skin     = new CapturingUpgraderSkin();
        $upgrader = ($this->upgraderFactory)($skin);
        $result   = $upgrader->upgrade($slug);

        if ($result === false) {
            $message = $skin->lastErrorMessage() ?? 'Theme_Upgrader returned false without a message.';
            throw new ThemeUpgradeFailedException($message);
        }
        if (is_wp_error($result)) {
            throw new ThemeUpgradeFailedException((string) $result->get_error_message());
        }

        // Re-read the version from disk after the upgrade. In production this
        // picks up the new version; under test the stub didn't swap files, so
        // we'll see the same version back.
        wp_clean_themes_cache();
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
            if (!class_exists(\Theme_Upgrader::class)) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
            }
            return new \Theme_Upgrader($skin);
        };
    }
}
