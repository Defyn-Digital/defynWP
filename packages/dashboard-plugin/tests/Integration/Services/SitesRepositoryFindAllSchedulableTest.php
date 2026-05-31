<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * F7 — SitesRepository::findAllSchedulable.
 *
 * Returns site IDs eligible for background sync/ping jobs: active + offline
 * + error (all have a completed handshake and a private key on file; even
 * error sites might recover). Pending sites are skipped — no handshake yet,
 * no private key, nothing to sign with.
 *
 * Used by the F7 fan-out master jobs (sync_all, health_ping_all) to enqueue
 * one leaf job per schedulable site.
 *
 * @group integration
 */
final class SitesRepositoryFindAllSchedulableTest extends AbstractSchemaTestCase
{
    private SitesRepository $repo;

    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $this->repo = new SitesRepository();
    }

    public function testReturnsActiveOfflineAndErrorIdsSkipsPending(): void
    {
        $activeId = $this->repo->insertPending(
            userId: 1,
            url: 'https://active.test',
            label: 'A',
            ourPublicKey: base64_encode(random_bytes(32)),
            ourPrivateKeyEncrypted: 'cipher',
        );
        $this->repo->markActive($activeId, base64_encode(random_bytes(32)));

        $offlineId = $this->repo->insertPending(
            userId: 1,
            url: 'https://offline.test',
            label: 'B',
            ourPublicKey: base64_encode(random_bytes(32)),
            ourPrivateKeyEncrypted: 'cipher',
        );
        $this->repo->markActive($offlineId, base64_encode(random_bytes(32)));
        $this->repo->markOffline($offlineId, 'previously offline');

        $errorId = $this->repo->insertPending(
            userId: 1,
            url: 'https://error.test',
            label: 'C',
            ourPublicKey: base64_encode(random_bytes(32)),
            ourPrivateKeyEncrypted: 'cipher',
        );
        $this->repo->markError($errorId, 'previously errored');

        $pendingId = $this->repo->insertPending(
            userId: 1,
            url: 'https://pending.test',
            label: 'D',
            ourPublicKey: base64_encode(random_bytes(32)),
            ourPrivateKeyEncrypted: 'cipher',
        );

        $ids = $this->repo->findAllSchedulable();

        self::assertContains($activeId, $ids);
        self::assertContains($offlineId, $ids);
        self::assertContains($errorId, $ids);
        self::assertNotContains($pendingId, $ids);
    }

    public function testRespectsLimit(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $id = $this->repo->insertPending(
                userId: 1,
                url: "https://site{$i}.test",
                label: "S{$i}",
                ourPublicKey: base64_encode(random_bytes(32)),
                ourPrivateKeyEncrypted: 'cipher',
            );
            $this->repo->markActive($id, base64_encode(random_bytes(32)));
        }

        $ids = $this->repo->findAllSchedulable(limit: 3);

        self::assertCount(3, $ids);
    }
}
