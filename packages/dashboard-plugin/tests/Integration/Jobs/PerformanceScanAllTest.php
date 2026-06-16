<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\PerformanceScan;
use Defyn\Dashboard\Jobs\PerformanceScanAll;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P6.1 — PerformanceScanAll fan-out master.
 *
 * Weekly AS job (every 7 days) that enqueues one `defyn_performance_scan`
 * leaf job per schedulable site — mirrors SecurityScanAll, minus the
 * one-off feed refresh (performance has no global feed step).
 *
 * @group integration
 */
final class PerformanceScanAllTest extends AbstractSchemaTestCase
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
            as_unschedule_all_actions(PerformanceScan::HOOK, null, 'defyn');
        }
    }

    public function testHookNameIsDefynPerformanceScanAll(): void
    {
        $this->assertSame('defyn_performance_scan_all', PerformanceScanAll::HOOK);
    }

    public function testSchedulesOnePerSchedulableSite(): void
    {
        $a = $this->repo->insertPending(userId: 1, url: 'https://a.test', label: 'A', ourPublicKey: 'pk', ourPrivateKeyEncrypted: 'enc');
        $this->repo->markActive($a, 'pk');

        $b = $this->repo->insertPending(userId: 1, url: 'https://b.test', label: 'B', ourPublicKey: 'pk', ourPrivateKeyEncrypted: 'enc');
        $this->repo->markActive($b, 'pk');

        (new PerformanceScanAll())->handle();

        self::assertNotFalse(as_next_scheduled_action(PerformanceScan::HOOK, [$a], 'defyn'));
        self::assertNotFalse(as_next_scheduled_action(PerformanceScan::HOOK, [$b], 'defyn'));
    }

    public function testSkipsPendingSites(): void
    {
        // Pending site — must be skipped (same filter as SecurityScanAll).
        $this->repo->insertPending(
            userId: 1,
            url: 'https://pending.test',
            label: 'Pending',
            ourPublicKey: 'pk',
            ourPrivateKeyEncrypted: 'enc',
        );

        (new PerformanceScanAll())->handle();

        $this->assertFalse(as_next_scheduled_action(PerformanceScan::HOOK, null, 'defyn'));
    }
}
