<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\HealthPing;
use Defyn\Dashboard\Jobs\HealthPingAll;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * F7 — HealthPingAll fan-out master.
 *
 * Recurring AS job (every 5 min) that enqueues one `defyn_health_ping` leaf
 * job per schedulable site (active + offline + error) — see spec § 6.3.
 *
 * @group integration
 */
final class HealthPingAllTest extends AbstractSchemaTestCase
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
            as_unschedule_all_actions(HealthPing::HOOK, null, 'defyn');
        }
    }

    public function testHookNameIsDefynHealthPingAll(): void
    {
        $this->assertSame('defyn_health_ping_all', HealthPingAll::HOOK);
    }

    public function testEnqueuesOneHealthPingPerSchedulableSite(): void
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

        (new HealthPingAll())->handle();

        self::assertNotFalse(as_next_scheduled_action(HealthPing::HOOK, [$idA], 'defyn'));
        self::assertNotFalse(as_next_scheduled_action(HealthPing::HOOK, [$idB], 'defyn'));
    }
}
