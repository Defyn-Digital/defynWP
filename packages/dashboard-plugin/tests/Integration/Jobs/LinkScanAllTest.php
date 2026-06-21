<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\LinkScan;
use Defyn\Dashboard\Jobs\LinkScanAll;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P7.1 — LinkScanAll fan-out master.
 *
 * Weekly AS job (every 7 days) that enqueues one `defyn_link_scan`
 * leaf job per schedulable site — mirrors PerformanceScanAll test shape.
 *
 * @group integration
 */
final class LinkScanAllTest extends AbstractSchemaTestCase
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
            as_unschedule_all_actions(LinkScan::HOOK, null, 'defyn');
        }
    }

    public function testHookNameIsDefynLinkScanAll(): void
    {
        $this->assertSame('defyn_link_scan_all', LinkScanAll::HOOK);
    }

    public function testSchedulesOnePerSchedulableSite(): void
    {
        $a = $this->repo->insertPending(userId: 1, url: 'https://a.test', label: 'A', ourPublicKey: 'pk', ourPrivateKeyEncrypted: 'enc');
        $this->repo->markActive($a, 'pk');

        $b = $this->repo->insertPending(userId: 1, url: 'https://b.test', label: 'B', ourPublicKey: 'pk', ourPrivateKeyEncrypted: 'enc');
        $this->repo->markActive($b, 'pk');

        (new LinkScanAll())->handle();

        self::assertNotFalse(as_next_scheduled_action(LinkScan::HOOK, [$a], 'defyn'));
        self::assertNotFalse(as_next_scheduled_action(LinkScan::HOOK, [$b], 'defyn'));
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

        (new LinkScanAll())->handle();

        $this->assertFalse(as_next_scheduled_action(LinkScan::HOOK, null, 'defyn'));
    }
}
