<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

/**
 * P6.3 — combined fleet Performance + Analytics rollup for /insights.
 * Read-only over the P6.1/P6.2 weekly snapshots. Direct payload (no {data}
 * envelope), mirroring SecurityService. Worst-first ordering + summaries are
 * computed here; the repositories only fetch the latest snapshot per site.
 */
final class InsightsService
{
    /** A mobile PSI score below this is a "slow site needing attention". */
    private const SLOW_SCORE_THRESHOLD = 50;

    public function __construct(
        private readonly SitePerformanceRepository $performance = new SitePerformanceRepository(),
        private readonly SiteAnalyticsRepository $analytics = new SiteAnalyticsRepository(),
    ) {
    }

    public function compose(int $userId): array
    {
        return [
            'performance'  => $this->performanceSection($userId),
            'analytics'    => $this->analyticsSection($userId),
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    private function performanceSection(int $userId): array
    {
        $rows = $this->performance->findFleetForUser($userId);

        $mobileScores = [];
        $desktopScores = [];
        $slow = 0;
        $measured = 0;
        foreach ($rows as $r) {
            if ($r['fetched_at'] === null) {
                continue;
            }
            $measured++;
            if ($r['mobile_score'] !== null) {
                $mobileScores[] = $r['mobile_score'];
                if ($r['mobile_score'] < self::SLOW_SCORE_THRESHOLD) {
                    $slow++;
                }
            }
            if ($r['desktop_score'] !== null) {
                $desktopScores[] = $r['desktop_score'];
            }
        }

        usort($rows, static function (array $a, array $b): int {
            // (1) measured before never-measured; (2) within measured, lowest mobile_score first.
            $am = $a['fetched_at'] !== null ? 0 : 1;
            $bm = $b['fetched_at'] !== null ? 0 : 1;
            if ($am !== $bm) {
                return $am <=> $bm;
            }
            if ($am === 0) {
                $cmp = ($a['mobile_score'] ?? PHP_INT_MAX) <=> ($b['mobile_score'] ?? PHP_INT_MAX);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return strcmp($a['label'], $b['label']);
        });

        return [
            'summary' => [
                'total_sites' => count($rows),
                'measured'    => $measured,
                'avg_mobile'  => $mobileScores === [] ? null : (int) round(array_sum($mobileScores) / count($mobileScores)),
                'avg_desktop' => $desktopScores === [] ? null : (int) round(array_sum($desktopScores) / count($desktopScores)),
                'slow_sites'  => $slow,
            ],
            'sites' => array_values($rows),
        ];
    }

    private function analyticsSection(int $userId): array
    {
        $rows = $this->analytics->findFleetForUser($userId);

        $connected = 0;
        $totalSessions = 0;
        $totalUsers = 0;
        foreach ($rows as $r) {
            if ($r['ga4_property_id'] !== null && $r['ga4_property_id'] !== '') {
                $connected++;
            }
            $totalSessions += $r['sessions'] ?? 0;
            $totalUsers    += $r['total_users'] ?? 0;
        }

        $rank = static function (array $x): int {
            $hasProp = $x['ga4_property_id'] !== null && $x['ga4_property_id'] !== '';
            if ($hasProp && $x['fetched_at'] !== null) {
                return 0; // connected with data
            }
            if ($hasProp) {
                return 1; // property but no snapshot
            }
            return 2;     // not connected
        };

        usort($rows, static function (array $a, array $b) use ($rank): int {
            $ra = $rank($a);
            $rb = $rank($b);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            if ($ra === 0) {
                // worst-first = lowest sessions first.
                $cmp = ($a['sessions'] ?? PHP_INT_MAX) <=> ($b['sessions'] ?? PHP_INT_MAX);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return strcmp($a['label'], $b['label']);
        });

        return [
            'summary' => [
                'total_sites'    => count($rows),
                'connected'      => $connected,
                'total_sessions' => $totalSessions,
                'total_users'    => $totalUsers,
            ],
            'sites' => array_values($rows),
        ];
    }
}
