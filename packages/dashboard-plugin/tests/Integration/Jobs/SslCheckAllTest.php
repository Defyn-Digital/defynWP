<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\SslCheck;
use Defyn\Dashboard\Jobs\SslCheckAll;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P3.3 — SslCheckAll fan-out master.
 *
 * Daily AS job (every 24 h) that enqueues one `defyn_ssl_check` leaf
 * job per schedulable site — mirrors HealthPingAll pattern.
 *
 * @group integration
 */
final class SslCheckAllTest extends AbstractSchemaTestCase
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
            as_unschedule_all_actions(SslCheck::HOOK, null, 'defyn');
        }
    }

    public function testHookNameIsDefynSslCheckAll(): void
    {
        $this->assertSame('defyn_ssl_check_all', SslCheckAll::HOOK);
    }

    public function testFansOutPerSchedulableSite(): void
    {
        $a = $this->repo->insertPending(userId: 1, url: 'https://a.test', label: 'A', ourPublicKey: 'pk', ourPrivateKeyEncrypted: 'enc');
        $this->repo->markActive($a, 'pk');

        (new SslCheckAll())->handle();

        self::assertNotFalse(as_next_scheduled_action(SslCheck::HOOK, [$a], 'defyn'));
    }

    public function testSkipsPendingSites(): void
    {
        // Pending site — must be skipped (same filter as HealthPingAll).
        $this->repo->insertPending(
            userId: 1,
            url: 'https://pending.test',
            label: 'Pending',
            ourPublicKey: 'pk',
            ourPrivateKeyEncrypted: 'enc',
        );

        (new SslCheckAll())->handle();

        $this->assertFalse(as_next_scheduled_action(SslCheck::HOOK, null, 'defyn'));
    }
}
