<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\Scheduler;
use Defyn\Dashboard\Jobs\SecurityScanAll;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P4.1 — SecurityScanAll recurring schedule.
 *
 * The daily security scan fan-out master must install via Scheduler
 * alongside the existing F7/P3.3 recurring actions.
 *
 * @group integration
 */
final class SchedulerSecurityTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Scheduler::uninstallRecurringSchedules();
    }

    public function testSecurityScanAllIsScheduledDaily(): void
    {
        Scheduler::installRecurringSchedules();
        self::assertNotFalse(as_next_scheduled_action(SecurityScanAll::HOOK, [], 'defyn'));
    }
}
