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

    /**
     * Build the full /monitoring fleet payload for $userId.
     *
     * Guardrails 5/6/9: one incidents query grouped in PHP; up=active,
     * down=offline (may sum < total when pending/error sites exist);
     * fleet_uptime_30d = mean of per-site uptime_30d (null when total===0);
     * slowest_ms = max latency (nulls excluded, null when none); UTC throughout.
     *
     * @return array{
     *     summary: array{total:int, up:int, down:int, fleet_uptime_30d:?float, slowest_ms:?int},
     *     sites: list<array<string, mixed>>,
     *     generated_at: string
     * }
     */
    public function compose(int $userId): array
    {
        $now         = time();
        $sevenStart  = $now - 7 * DAY_IN_SECONDS;
        $thirtyStart = $now - 30 * DAY_IN_SECONDS;
        $sinceUtc    = gmdate('Y-m-d H:i:s', $thirtyStart);

        $sites        = (new SitesRepository())->findAllForUser($userId);
        $incidentRows = (new IncidentsRepository())->findForUserSince($userId, $sinceUtc);

        // Group incidents by site, converting UTC strings → epoch (forced UTC).
        $bySite = [];
        foreach ($incidentRows as $r) {
            $bySite[$r['site_id']][] = [
                'started'     => strtotime($r['started_at'] . ' UTC'),
                'ended'       => $r['ended_at'] !== null ? strtotime($r['ended_at'] . ' UTC') : null,
                'started_raw' => $r['started_at'],
            ];
        }

        $siteOut = [];
        $up = 0;
        $down = 0;
        $uptime30Sum = 0.0;
        $slowest = null;

        foreach ($sites as $site) {
            $incidents = $bySite[$site->id] ?? [];

            if ($site->status === 'active') {
                $up++;
            } elseif ($site->status === 'offline') {
                $down++;
            }

            $u7  = self::uptimePercent($incidents, $sevenStart, $now);
            $u30 = self::uptimePercent($incidents, $thirtyStart, $now);
            $uptime30Sum += $u30;

            $openStarted = null;
            foreach ($incidents as $inc) {
                if ($inc['ended'] === null) {
                    $openStarted = $inc['started_raw'];
                    break;
                }
            }

            $ms = $site->lastResponseTimeMs;
            if ($ms !== null && ($slowest === null || $ms > $slowest)) {
                $slowest = $ms;
            }

            $siteOut[] = [
                'site_id'                  => $site->id,
                'label'                    => $site->label,
                'url'                      => $site->url,
                'status'                   => $site->status,
                'last_response_time_ms'    => $ms,
                'last_contact_at'          => $site->lastContactAt,
                'uptime_7d'                => $u7,
                'uptime_30d'               => $u30,
                'open_incident_started_at' => $openStarted,
            ];
        }

        $total = count($sites);

        return [
            'summary' => [
                'total'            => $total,
                'up'               => $up,
                'down'             => $down,
                'fleet_uptime_30d' => $total > 0 ? round($uptime30Sum / $total, 2) : null,
                'slowest_ms'       => $slowest,
            ],
            'sites'        => $siteOut,
            'generated_at' => gmdate('Y-m-d H:i:s', $now),
        ];
    }
}
