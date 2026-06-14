<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/**
 * P3.2 — composes the /monitoring fleet payload. Uptime is DERIVED from the
 * incident history (never stored): uptimePercent is a pure function over UTC
 * integer timestamps.
 */
final class MonitoringService
{
    /**
     * Uptime-% over [windowStartTs, nowTs] given incidents overlapping it.
     *
     * @param array<int,array{started:int,ended:?int}> $incidents ended=null → still open (down to now)
     */
    public static function uptimePercent(array $incidents, int $windowStartTs, int $nowTs): float
    {
        $window = $nowTs - $windowStartTs;
        if ($window <= 0) {
            return 100.0;
        }

        $downtime = 0;
        foreach ($incidents as $incident) {
            $start = (int) $incident['started'];
            $end   = $incident['ended'] !== null ? (int) $incident['ended'] : $nowTs;
            $overlap = min($end, $nowTs) - max($start, $windowStartTs);
            if ($overlap > 0) {
                $downtime += $overlap;
            }
        }

        $pct = (1 - $downtime / $window) * 100;
        return round(max(0.0, min(100.0, $pct)), 2);
    }
}
