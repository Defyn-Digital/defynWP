<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\AnalyticsSync;
use Defyn\Dashboard\Jobs\AnalyticsSyncAll;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P6.2 — AnalyticsSyncAll fan-out master.
 *
 * Weekly AS job (every 7 days) that enqueues one `defyn_analytics_sync`
 * leaf job per schedulable site — mirrors PerformanceScanAll (whole-fleet
 * SYSTEM cron, findAllSchedulable, no global feed step).
 *
 * @group integration
 */
final class AnalyticsSyncAllTest extends AbstractSchemaTestCase
{
    private SitesRepository $repo;

    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $this->repo = new SitesRepository();

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(AnalyticsSync::HOOK, null, 'defyn');
        }
    }

    public function testHookNameIsDefynAnalyticsSyncAll(): void
    {
        $this->assertSame('defyn_analytics_sync_all', AnalyticsSyncAll::HOOK);
    }

    public function testSchedulesOnePerSchedulableSite(): void
    {
        $a = $this->repo->insertPending(userId: 1, url: 'https://a.test', label: 'A', ourPublicKey: 'pk', ourPrivateKeyEncrypted: 'enc');
        $this->repo->markActive($a, 'pk');

        $b = $this->repo->insertPending(userId: 1, url: 'https://b.test', label: 'B', ourPublicKey: 'pk', ourPrivateKeyEncrypted: 'enc');
        $this->repo->markActive($b, 'pk');

        (new AnalyticsSyncAll())->handle();

        self::assertNotFalse(as_next_scheduled_action(AnalyticsSync::HOOK, [$a], 'defyn'));
        self::assertNotFalse(as_next_scheduled_action(AnalyticsSync::HOOK, [$b], 'defyn'));
    }

    public function testSkipsPendingSites(): void
    {
        // Pending site — must be skipped (same filter as PerformanceScanAll).
        $this->repo->insertPending(
            userId: 1,
            url: 'https://pending.test',
            label: 'Pending',
            ourPublicKey: 'pk',
            ourPrivateKeyEncrypted: 'enc',
        );

        (new AnalyticsSyncAll())->handle();

        $this->assertFalse(as_next_scheduled_action(AnalyticsSync::HOOK, null, 'defyn'));
    }
}
