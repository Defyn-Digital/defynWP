<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\SecurityScan;
use Defyn\Dashboard\Jobs\SecurityScanAll;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P4.1 — SecurityScanAll fan-out master.
 *
 * Daily AS job (every 24 h) that refreshes the global vuln feed once,
 * then enqueues one `defyn_security_scan` leaf job per schedulable
 * site — mirrors SslCheckAll / HealthPingAll pattern.
 *
 * @group integration
 */
final class SecurityScanAllTest extends AbstractSchemaTestCase
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
            as_unschedule_all_actions(SecurityScan::HOOK, null, 'defyn');
        }
    }

    public function testHookNameIsDefynSecurityScanAll(): void
    {
        $this->assertSame('defyn_security_scan_all', SecurityScanAll::HOOK);
    }

    public function testSchedulesOnePerSchedulableSite(): void
    {
        $a = $this->repo->insertPending(userId: 1, url: 'https://a.test', label: 'A', ourPublicKey: 'pk', ourPrivateKeyEncrypted: 'enc');
        $this->repo->markActive($a, 'pk');

        $b = $this->repo->insertPending(userId: 1, url: 'https://b.test', label: 'B', ourPublicKey: 'pk', ourPrivateKeyEncrypted: 'enc');
        $this->repo->markActive($b, 'pk');

        (new SecurityScanAll())->handle();

        self::assertNotFalse(as_next_scheduled_action(SecurityScan::HOOK, [$a], 'defyn'));
        self::assertNotFalse(as_next_scheduled_action(SecurityScan::HOOK, [$b], 'defyn'));
    }

    public function testSkipsPendingSites(): void
    {
        // Pending site — must be skipped (same filter as SslCheckAll).
        $this->repo->insertPending(
            userId: 1,
            url: 'https://pending.test',
            label: 'Pending',
            ourPublicKey: 'pk',
            ourPrivateKeyEncrypted: 'enc',
        );

        (new SecurityScanAll())->handle();

        $this->assertFalse(as_next_scheduled_action(SecurityScan::HOOK, null, 'defyn'));
    }
}
