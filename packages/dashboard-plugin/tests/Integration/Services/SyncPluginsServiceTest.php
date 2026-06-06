<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SyncPluginsService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * @group integration
 */
final class SyncPluginsServiceTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_site_plugins');
        $this->freshlyActivate('defyn_activity_log');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitePluginsTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
    }

    public function testSyncHealsDanglingFailedRowWhenUpdateNoLongerAvailable(): void
    {
        // Seed a stuck-failed row mirroring the gbposter cosmetic on production:
        // a previous P2.2 buggy run marked the row failed even though the
        // upgrade actually succeeded. Now the next inventory sync arrives with
        // the new version + update_available=false but update_state stayed failed.
        global $wpdb;
        $wpdb->insert(SitePluginsTable::tableName(), [
            'site_id'           => 1,
            'slug'              => 'gbposter',
            'name'              => 'GBPoster',
            'version'           => '1.1.0',
            'update_available'  => 1,
            'update_version'    => '2.0.0',
            'update_state'      => 'failed',
            'last_update_error' => 'Connector returned HTTP 200.',
            'last_seen_at'      => '2026-06-06 00:00:00',
            'created_at'        => '2026-06-06 00:00:00',
            'updated_at'        => '2026-06-06 00:00:00',
        ]);

        // Incoming sync: gbposter now at 2.0.0, no update available — the
        // upgrade clearly succeeded out-of-band.
        (new SyncPluginsService())->sync(1, [
            'plugins' => [[
                'slug' => 'gbposter', 'name' => 'GBPoster', 'version' => '2.0.0',
                'update_available' => false, 'update_version' => null,
            ]],
        ], 'background');

        $row = (new SitePluginsRepository())->findRowForSiteAndSlug(1, 'gbposter');
        self::assertSame('idle', $row['update_state'], 'Dangling failed state should be reset to idle.');
        self::assertNull($row['last_update_error'], 'Stale error message should be cleared.');
        self::assertSame('2.0.0', $row['version'], 'Version should reflect the post-upgrade reality.');
    }

    public function testSyncDoesNotHealRowsWithActiveUpdate(): void
    {
        // A row that failed but a NEW update is available — operator needs to
        // see the prior error before clicking Retry on the new target.
        global $wpdb;
        $wpdb->insert(SitePluginsTable::tableName(), [
            'site_id'           => 1,
            'slug'              => 'flaky',
            'name'              => 'Flaky',
            'version'           => '1.0.0',
            'update_available'  => 1,
            'update_version'    => '1.1.0',
            'update_state'      => 'failed',
            'last_update_error' => 'Could not copy file.',
            'last_seen_at'      => '2026-06-06 00:00:00',
            'created_at'        => '2026-06-06 00:00:00',
            'updated_at'        => '2026-06-06 00:00:00',
        ]);

        // Incoming sync still reports an available update for flaky.
        (new SyncPluginsService())->sync(1, [
            'plugins' => [[
                'slug' => 'flaky', 'name' => 'Flaky', 'version' => '1.0.0',
                'update_available' => true, 'update_version' => '1.2.0',
            ]],
        ], 'background');

        $row = (new SitePluginsRepository())->findRowForSiteAndSlug(1, 'flaky');
        self::assertSame('failed', $row['update_state'], 'Failed state should persist when update_available is still true.');
        self::assertSame('Could not copy file.', $row['last_update_error']);
    }

    public function testSyncPersistsPluginsAndLogsEvent(): void
    {
        (new SyncPluginsService())->sync(1, [
            'plugins' => [
                ['slug' => 'a.php', 'name' => 'A', 'version' => '1.0', 'update_available' => true,  'update_version' => '1.1'],
                ['slug' => 'b.php', 'name' => 'B', 'version' => '2.0', 'update_available' => false, 'update_version' => null],
            ],
        ], 'background');

        $rows = (new SitePluginsRepository())->findAllForSite(1);
        self::assertCount(2, $rows);

        global $wpdb;
        $events = $wpdb->get_results(
            'SELECT event_type, details, site_id, user_id FROM ' . ActivityLogTable::tableName() . ' ORDER BY id DESC',
            ARRAY_A,
        );
        self::assertCount(1, $events);
        self::assertSame('plugin_inventory.synced', $events[0]['event_type']);
        self::assertSame('1', (string) $events[0]['site_id']);
        self::assertNull($events[0]['user_id']);
        $details = json_decode((string) $events[0]['details'], true);
        self::assertSame(2,            $details['plugin_count']);
        self::assertSame(1,            $details['updates_available_count']);
        self::assertSame('background', $details['source']);
    }

    public function testSyncWithEmptyPluginsListClearsRowsAndLogsZero(): void
    {
        (new SyncPluginsService())->sync(1, [
            'plugins' => [['slug' => 'a.php', 'name' => 'A', 'version' => '1', 'update_available' => false, 'update_version' => null]],
        ], 'background');

        (new SyncPluginsService())->sync(1, ['plugins' => []], 'refresh');

        $rows = (new SitePluginsRepository())->findAllForSite(1);
        self::assertSame([], $rows);

        global $wpdb;
        $latest = $wpdb->get_row(
            'SELECT event_type, details FROM ' . ActivityLogTable::tableName() . ' ORDER BY id DESC LIMIT 1',
            ARRAY_A,
        );
        $details = json_decode((string) $latest['details'], true);
        self::assertSame(0,         $details['plugin_count']);
        self::assertSame(0,         $details['updates_available_count']);
        self::assertSame('refresh', $details['source']);
    }
}
