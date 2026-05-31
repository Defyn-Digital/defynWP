<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Jobs\CleanupExpiredCodes;
use Defyn\Dashboard\Jobs\HealthPingAll;
use Defyn\Dashboard\Jobs\Scheduler;
use Defyn\Dashboard\Jobs\SyncAllSites;

/**
 * F7 — Activation wires recurring schedules + Plugin::boot wires AS handlers.
 *
 * Without these wirings, the schedules from Task 5 wouldn't install on
 * activation and the handlers from Tasks 2-4 wouldn't execute when AS fires.
 *
 * @group integration
 */
final class ActivationRecurringSchedulesTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Start clean so install assertions are unambiguous.
        Scheduler::uninstallRecurringSchedules();
    }

    public function testActivationInstallsRecurringSchedules(): void
    {
        Activation::activate();

        $this->assertNotFalse(as_next_scheduled_action(SyncAllSites::HOOK,        [], 'defyn'));
        $this->assertNotFalse(as_next_scheduled_action(HealthPingAll::HOOK,       [], 'defyn'));
        $this->assertNotFalse(as_next_scheduled_action(CleanupExpiredCodes::HOOK, [], 'defyn'));
    }

    public function testThreeNewHookHandlersAreRegistered(): void
    {
        // Plugin::boot runs at PHPUnit bootstrap (defyn-dashboard.php loads it).
        $this->assertNotFalse(has_action(SyncAllSites::HOOK));
        $this->assertNotFalse(has_action(HealthPingAll::HOOK));
        $this->assertNotFalse(has_action(CleanupExpiredCodes::HOOK));
    }
}
