<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Services;

use Defyn\Dashboard\Services\MonitoringService;
use PHPUnit\Framework\TestCase;

final class MonitoringServiceUptimeTest extends TestCase
{
    private const NOW = 1_000_000;          // arbitrary epoch
    private const WINDOW_START = 991_360;   // NOW - 8640 (a 2.4h window for easy maths)

    public function testNoIncidentsIsHundred(): void
    {
        self::assertSame(100.0, MonitoringService::uptimePercent([], self::WINDOW_START, self::NOW));
    }

    public function testZeroLengthWindowIsHundred(): void
    {
        self::assertSame(100.0, MonitoringService::uptimePercent([], self::NOW, self::NOW));
    }

    public function testOneFullyInWindowClosedIncident(): void
    {
        // 864s down inside an 8640s window = 10% down = 90% up.
        $incidents = [['started' => self::NOW - 5000, 'ended' => self::NOW - 4136]];
        self::assertSame(90.0, MonitoringService::uptimePercent($incidents, self::WINDOW_START, self::NOW));
    }

    public function testOpenIncidentCountsToNow(): void
    {
        // open incident started 864s ago, still open → 10% down.
        $incidents = [['started' => self::NOW - 864, 'ended' => null]];
        self::assertSame(90.0, MonitoringService::uptimePercent($incidents, self::WINDOW_START, self::NOW));
    }

    public function testIncidentStartingBeforeWindowCountsOnlyInWindowPortion(): void
    {
        // started 4320s before window start, ended at windowStart+864 → only 864s in-window.
        $incidents = [['started' => self::WINDOW_START - 4320, 'ended' => self::WINDOW_START + 864]];
        self::assertSame(90.0, MonitoringService::uptimePercent($incidents, self::WINDOW_START, self::NOW));
    }

    public function testMultipleOverlappingAccumulate(): void
    {
        $incidents = [
            ['started' => self::NOW - 5000, 'ended' => self::NOW - 4568], // 432s
            ['started' => self::NOW - 2000, 'ended' => self::NOW - 1568], // 432s
        ];
        // 864s down / 8640 = 10% → 90% up.
        self::assertSame(90.0, MonitoringService::uptimePercent($incidents, self::WINDOW_START, self::NOW));
    }

    public function testClampsToZeroWhenDowntimeExceedsWindow(): void
    {
        $incidents = [['started' => self::WINDOW_START - 100_000, 'ended' => null]];
        self::assertSame(0.0, MonitoringService::uptimePercent($incidents, self::WINDOW_START, self::NOW));
    }
}
