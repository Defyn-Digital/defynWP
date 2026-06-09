<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\ThemesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P2.8 — Tests for ThemesRepository::findAllPendingUpdatesForUser.
 *
 * Mirrors P2.7's SitePluginsRepositoryPendingUpdatesTest with table-name swap.
 */
final class ThemesRepositoryPendingUpdatesTest extends AbstractSchemaTestCase
{
    private ThemesRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_site_themes');

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_themes");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");

        $this->repo = new ThemesRepository();
    }

    public function testFindAllPendingUpdatesForUserReturnsCorrectRowsAcrossSites(): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert("{$wpdb->prefix}defyn_sites", [
            'user_id'    => 1,
            'url'        => 'https://smartcoding.test',
            'label'      => 'SmartCoding',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $siteAId = (int) $wpdb->insert_id;

        $wpdb->insert("{$wpdb->prefix}defyn_sites", [
            'user_id'    => 1,
            'url'        => 'https://acmeblog.test',
            'label'      => 'AcmeBlog',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $siteBId = (int) $wpdb->insert_id;

        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $siteAId,
            'slug'             => 'astra',
            'name'             => 'Astra',
            'version'          => '4.6.3',
            'update_available' => 1,
            'update_version'   => '4.7.0',
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $siteAId,
            'slug'             => 'twentytwentyfour',
            'name'             => 'Twenty TwentyFour',
            'version'          => '1.3',
            'update_available' => 0,
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $siteBId,
            'slug'             => 'blocksy',
            'name'             => 'Blocksy',
            'version'          => '2.0.1',
            'update_available' => 1,
            'update_version'   => '2.0.2',
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $rows = $this->repo->findAllPendingUpdatesForUser(1);

        $this->assertCount(2, $rows);
        $this->assertSame('AcmeBlog', $rows[0]['site_label']);
        $this->assertSame('blocksy', $rows[0]['slug']);
        $this->assertSame('Blocksy', $rows[0]['theme_name']);
        $this->assertSame('2.0.1', $rows[0]['current_version']);
        $this->assertSame('2.0.2', $rows[0]['target_version']);
        $this->assertSame('SmartCoding', $rows[1]['site_label']);
        $this->assertSame('astra', $rows[1]['slug']);
        $this->assertSame('Astra', $rows[1]['theme_name']);
    }

    public function testFindAllPendingUpdatesForUserExcludesOtherUsers(): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert("{$wpdb->prefix}defyn_sites", [
            'user_id'    => 1,
            'url'        => 'https://mine.test',
            'label'      => 'Mine',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $mineId = (int) $wpdb->insert_id;

        $wpdb->insert("{$wpdb->prefix}defyn_sites", [
            'user_id'    => 2,
            'url'        => 'https://theirs.test',
            'label'      => 'Theirs',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $theirsId = (int) $wpdb->insert_id;

        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $mineId,
            'slug'             => 'astra',
            'name'             => 'Astra',
            'version'          => '4.6.3',
            'update_available' => 1,
            'update_version'   => '4.7.0',
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $theirsId,
            'slug'             => 'kadence',
            'name'             => 'Kadence',
            'version'          => '1.1.40',
            'update_available' => 1,
            'update_version'   => '1.2.0',
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $rowsForUser1 = $this->repo->findAllPendingUpdatesForUser(1);
        $rowsForUser2 = $this->repo->findAllPendingUpdatesForUser(2);

        $this->assertCount(1, $rowsForUser1);
        $this->assertSame('astra', $rowsForUser1[0]['slug']);
        $this->assertCount(1, $rowsForUser2);
        $this->assertSame('kadence', $rowsForUser2[0]['slug']);
    }

    public function testFindAllPendingUpdatesForUserExcludesRowsWithoutAvailableUpdate(): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert("{$wpdb->prefix}defyn_sites", [
            'user_id'    => 1,
            'url'        => 'https://test.test',
            'label'      => 'Test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $siteId = (int) $wpdb->insert_id;

        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $siteId,
            'slug'             => 'astra',
            'name'             => 'Astra',
            'version'          => '4.7.0',
            'update_available' => 0,
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $rows = $this->repo->findAllPendingUpdatesForUser(1);

        $this->assertSame([], $rows);
    }
}
