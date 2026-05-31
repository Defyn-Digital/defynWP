<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\SyncAllSites;
use Defyn\Dashboard\Jobs\SyncSite;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * F7 — SyncAllSites fan-out master.
 *
 * Recurring AS job (every 30 min) that enqueues one `defyn_sync_site` leaf
 * job per schedulable site (active + offline + error) — see spec § 6.3.
 *
 * @group integration
 */
final class SyncAllSitesTest extends AbstractSchemaTestCase
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
            as_unschedule_all_actions(SyncSite::HOOK, null, 'defyn');
        }
    }

    public function testHookNameIsDefynSyncAllSites(): void
    {
        $this->assertSame('defyn_sync_all_sites', SyncAllSites::HOOK);
    }

    public function testEnqueuesOneSyncSitePerSchedulableSite(): void
    {
        $idA = $this->repo->insertPending(
            userId: 1,
            url: 'https://a.test',
            label: 'A',
            ourPublicKey: base64_encode(random_bytes(32)),
            ourPrivateKeyEncrypted: 'cipher',
        );
        $this->repo->markActive($idA, base64_encode(random_bytes(32)));

        $idB = $this->repo->insertPending(
            userId: 1,
            url: 'https://b.test',
            label: 'B',
            ourPublicKey: base64_encode(random_bytes(32)),
            ourPrivateKeyEncrypted: 'cipher',
        );
        $this->repo->markActive($idB, base64_encode(random_bytes(32)));

        // Pending site — must be skipped (Task 1 filter).
        $this->repo->insertPending(
            userId: 1,
            url: 'https://c.test',
            label: 'C',
            ourPublicKey: base64_encode(random_bytes(32)),
            ourPrivateKeyEncrypted: 'cipher',
        );

        (new SyncAllSites())->handle();

        self::assertNotFalse(as_next_scheduled_action(SyncSite::HOOK, [$idA], 'defyn'));
        self::assertNotFalse(as_next_scheduled_action(SyncSite::HOOK, [$idB], 'defyn'));
    }
}
