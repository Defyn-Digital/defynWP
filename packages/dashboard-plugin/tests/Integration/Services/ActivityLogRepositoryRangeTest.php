<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\ActivityLogRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ActivityLogRepositoryRangeTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_activity_log');
    }

    public function testFindUpdatesForSiteInRangeFiltersByTypeSiteAndDate(): void
    {
        $repo = new ActivityLogRepository();
        $repo->insert(null, 7, 'plugin_update.succeeded', ['slug'=>'akismet','previous_version'=>'5.3','new_version'=>'5.4'], null);
        $this->insertAt($repo, 7, 'plugin_update.succeeded', ['slug'=>'old','previous_version'=>'1','new_version'=>'2'], '2020-01-01 00:00:00'); // out of range
        $repo->insert(null, 7, 'plugin_update.started', ['slug'=>'x'], null); // wrong type
        $repo->insert(null, 8, 'plugin_update.succeeded', ['slug'=>'other','previous_version'=>'1','new_version'=>'2'], null); // wrong site

        $rows = $repo->findUpdatesForSiteInRange(7, '2026-01-01 00:00:00', '2030-01-01 23:59:59');

        self::assertCount(1, $rows);
        self::assertSame('plugin_update.succeeded', $rows[0]['event_type']);
        self::assertSame('akismet', $rows[0]['details']['slug']);
        self::assertSame('5.4', $rows[0]['details']['new_version']);
    }

    public function testFindSecurityScansForSiteInRange(): void
    {
        $repo = new ActivityLogRepository();
        $repo->insert(null, 7, 'site.vulnerabilities_detected', ['total'=>2,'critical'=>0,'high'=>1,'medium'=>1,'low'=>0], null);
        $repo->insert(null, 7, 'site.new_vulnerabilities', ['new_count'=>1], null); // wrong type

        $rows = $repo->findSecurityScansForSiteInRange(7, '2026-01-01 00:00:00', '2030-01-01 23:59:59');

        self::assertCount(1, $rows);
        self::assertSame('site.vulnerabilities_detected', $rows[0]['event_type']);
        self::assertSame(2, $rows[0]['details']['total']);
    }

    private function insertAt(ActivityLogRepository $repo, int $siteId, string $type, array $details, string $createdAt): void
    {
        $id = $repo->insert(null, $siteId, $type, $details, null);
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'defyn_activity_log SET created_at = %s WHERE id = %d', $createdAt, $id));
    }
}
