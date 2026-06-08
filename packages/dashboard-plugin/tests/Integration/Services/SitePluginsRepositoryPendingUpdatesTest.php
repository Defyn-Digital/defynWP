<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * @group integration
 */
final class SitePluginsRepositoryPendingUpdatesTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_site_plugins');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query("TRUNCATE {$wpdb->prefix}defyn_site_plugins");
        $wpdb->query("TRUNCATE {$wpdb->prefix}defyn_sites");
        // phpcs:enable WordPress.DB.PreparedSQL
    }

    public function testFindAllPendingUpdatesForUserReturnsCorrectRowsAcrossSites(): void
    {
        $siteA = $this->seedSite(1, 'SmartCoding');
        $siteB = $this->seedSite(1, 'AcmeBlog');

        $this->seedPlugin($siteA, 'akismet', 'Akismet Anti-Spam', '5.3', '5.3.1', true);
        $this->seedPlugin($siteA, 'yoast',   'Yoast SEO',         '22.5', '22.6', true);
        $this->seedPlugin($siteA, 'wpml',    'WPML',              '4.7',  null,   false); // no update
        $this->seedPlugin($siteB, 'jetpack', 'Jetpack',           '13.1', '13.2', true);

        $rows = (new SitePluginsRepository())->findAllPendingUpdatesForUser(1);

        $this->assertCount(3, $rows);
        $slugs = array_map(static fn($r) => $r['slug'], $rows);
        $this->assertEqualsCanonicalizing(['akismet', 'yoast', 'jetpack'], $slugs);

        // Verify shape for akismet row.
        $akismet = null;
        foreach ($rows as $row) {
            if ($row['slug'] === 'akismet') {
                $akismet = $row;
                break;
            }
        }
        $this->assertNotNull($akismet);
        $this->assertSame($siteA, $akismet['site_id']);
        $this->assertSame('SmartCoding', $akismet['site_label']);
        $this->assertSame('Akismet Anti-Spam', $akismet['plugin_name']);
        $this->assertSame('5.3', $akismet['current_version']);
        $this->assertSame('5.3.1', $akismet['target_version']);
    }

    public function testFindAllPendingUpdatesForUserExcludesOtherUsers(): void
    {
        $siteA = $this->seedSite(1, 'SmartCoding');
        $siteB = $this->seedSite(2, 'NotMine');

        $this->seedPlugin($siteA, 'akismet', 'Akismet', '5.3', '5.3.1', true);
        $this->seedPlugin($siteB, 'yoast',   'Yoast',   '22.5', '22.6', true);

        $rows = (new SitePluginsRepository())->findAllPendingUpdatesForUser(1);
        $this->assertCount(1, $rows);
        $this->assertSame('akismet', $rows[0]['slug']);

        $rows2 = (new SitePluginsRepository())->findAllPendingUpdatesForUser(2);
        $this->assertCount(1, $rows2);
        $this->assertSame('yoast', $rows2[0]['slug']);
    }

    private function seedSite(int $userId, string $label): int
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://ex-' . uniqid() . '.com',
            'label'      => $label,
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    private function seedPlugin(int $siteId, string $slug, string $name, string $version, ?string $updateVersion, bool $updateAvailable): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'defyn_site_plugins', [
            'site_id'          => $siteId,
            'slug'             => $slug,
            'name'             => $name,
            'version'          => $version,
            'update_version'   => $updateVersion,
            'update_available' => $updateAvailable ? 1 : 0,
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }
}
