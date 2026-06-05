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
