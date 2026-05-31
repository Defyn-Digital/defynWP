<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\CleanupExpiredCodes;
use Defyn\Dashboard\Jobs\HealthPingAll;
use Defyn\Dashboard\Jobs\Scheduler;
use Defyn\Dashboard\Jobs\SyncAllSites;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * F7 — Scheduler install/uninstall helper.
 *
 * Single source of truth for the F7 recurring AS schedules (spec § 6.3).
 * Activation hook calls install; deactivation hook calls uninstall.
 * install is idempotent — repeated activation must not duplicate recurring rows.
 *
 * @group integration
 */
final class SchedulerTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Start clean so install assertions are unambiguous.
        Scheduler::uninstallRecurringSchedules();
    }

    public function testInstallSchedulesAllThreeRecurringActions(): void
    {
        Scheduler::installRecurringSchedules();

        $this->assertNotFalse(as_next_scheduled_action(SyncAllSites::HOOK,         [], 'defyn'));
        $this->assertNotFalse(as_next_scheduled_action(HealthPingAll::HOOK,        [], 'defyn'));
        $this->assertNotFalse(as_next_scheduled_action(CleanupExpiredCodes::HOOK,  [], 'defyn'));
    }

    public function testInstallIsIdempotent(): void
    {
        Scheduler::installRecurringSchedules();
        Scheduler::installRecurringSchedules();

        // Each hook must have exactly one scheduled recurring action — not two.
        $this->assertCount(1, as_get_scheduled_actions([
            'hook'   => SyncAllSites::HOOK,
            'group'  => 'defyn',
            'status' => 'pending',
        ], 'ids'));
    }

    public function testUninstallRemovesAllSchedules(): void
    {
        Scheduler::installRecurringSchedules();
        Scheduler::uninstallRecurringSchedules();

        $this->assertFalse(as_next_scheduled_action(SyncAllSites::HOOK,        [], 'defyn'));
        $this->assertFalse(as_next_scheduled_action(HealthPingAll::HOOK,       [], 'defyn'));
        $this->assertFalse(as_next_scheduled_action(CleanupExpiredCodes::HOOK, [], 'defyn'));
    }
}
