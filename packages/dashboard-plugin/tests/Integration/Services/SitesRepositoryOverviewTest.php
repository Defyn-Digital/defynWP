<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesRepositoryOverviewTest extends AbstractSchemaTestCase
{
    public function testCountPendingPluginsReturnsZeroWhenNoSites(): void
    {
        $this->assertSame(0, (new SitesRepository())->countPendingPlugins(1));
    }

    public function testCountPendingPluginsReturnsCorrectCountAcrossOwnedSites(): void
    {
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(1);
        $siteC = $this->seedSite(2); // different user

        $this->seedPlugin($siteA, 'akismet/akismet.php', true);
        $this->seedPlugin($siteA, 'yoast/yoast.php', true);
        $this->seedPlugin($siteB, 'jetpack/jetpack.php', true);
        $this->seedPlugin($siteC, 'wpml/wpml.php', true); // owned by user 2 — must NOT count for user 1

        $this->assertSame(3, (new SitesRepository())->countPendingPlugins(1));
        $this->assertSame(1, (new SitesRepository())->countPendingPlugins(2));
    }

    public function testCountPendingThemesReturnsCorrectCountAcrossOwnedSites(): void
    {
        $siteA = $this->seedSite(1);
        $this->seedTheme($siteA, 'twentytwentyfour', true);
        $this->seedTheme($siteA, 'astra', false); // no update available — must NOT count

        $this->assertSame(1, (new SitesRepository())->countPendingThemes(1));
    }

    public function testCountPendingCoresMinorReturnsCorrectCount(): void
    {
        global $wpdb;
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(1);

        // siteA: 7.0 -> 7.0.1 (minor)
        $wpdb->update($wpdb->prefix . 'defyn_sites', [
            'wp_version' => '7.0',
            'core_update_available' => 1,
            'core_update_version' => '7.0.1',
        ], ['id' => $siteA]);

        // siteB: 7.0 -> 8.0 (major)
        $wpdb->update($wpdb->prefix . 'defyn_sites', [
            'wp_version' => '7.0',
            'core_update_available' => 1,
            'core_update_version' => '8.0',
        ], ['id' => $siteB]);

        $repo = new SitesRepository();
        $this->assertSame(1, $repo->countPendingCoresMinor(1));
        $this->assertSame(1, $repo->countPendingCoresMajor(1));
    }

    public function testCountSitesWithAnyUpdateUnionsAcrossPluginThemeAndCore(): void
    {
        global $wpdb;
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(1);
        $siteC = $this->seedSite(1);

        $this->seedPlugin($siteA, 'akismet/akismet.php', true);          // siteA has plugin update
        $this->seedTheme($siteB, 'twentytwentyfour', true);              // siteB has theme update
        $wpdb->update($wpdb->prefix . 'defyn_sites', [
            'wp_version' => '7.0',
            'core_update_available' => 1,
            'core_update_version' => '7.0.1',
        ], ['id' => $siteC]);                                            // siteC has core update

        $this->assertSame(3, (new SitesRepository())->countSitesWithAnyUpdate(1));
    }

    private function seedSite(int $userId): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://example' . microtime(true) . '.com',
            'label'      => 'Example',
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function seedPlugin(int $siteId, string $slug, bool $updateAvailable): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_site_plugins', [
            'site_id'          => $siteId,
            'slug'             => $slug,
            'name'             => $slug,
            'version'          => '1.0',
            'update_available' => $updateAvailable ? 1 : 0,
            'last_seen_at'     => gmdate('Y-m-d H:i:s'),
            'created_at'       => gmdate('Y-m-d H:i:s'),
            'updated_at'       => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function seedTheme(int $siteId, string $slug, bool $updateAvailable): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_site_themes', [
            'site_id'          => $siteId,
            'slug'             => $slug,
            'name'             => $slug,
            'version'          => '1.0',
            'is_active'        => 0,
            'update_available' => $updateAvailable ? 1 : 0,
            'last_seen_at'     => gmdate('Y-m-d H:i:s'),
            'created_at'       => gmdate('Y-m-d H:i:s'),
            'updated_at'       => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
