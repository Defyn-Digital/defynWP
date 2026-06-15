<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

/**
 * P4.2 — fleet security rollup. Mirrors MonitoringService: a direct payload
 * (no {data} envelope) with a summary + a status-sorted site list.
 */
final class SecurityService
{
    public function __construct(
        private readonly SiteVulnerabilitiesRepository $findings = new SiteVulnerabilitiesRepository(),
    ) {
    }

    public function compose(int $userId): array
    {
        $rows = $this->findings->findFleetSummariesForUser($userId);

        $totalSites   = count($rows);
        $scannedSites = 0;
        $sitesAtRisk  = 0;
        $crit = 0; $high = 0; $med = 0; $low = 0;

        foreach ($rows as $r) {
            if ($r['last_security_scan_at'] !== null) {
                $scannedSites++;
            }
            if ($r['total'] > 0) {
                $sitesAtRisk++;
            }
            $crit += $r['critical'];
            $high += $r['high'];
            $med  += $r['medium'];
            $low  += $r['low'];
        }

        // Sort: (1) at-risk (total>0) worst-severity first; (2) scanned-clean; (3) never-scanned.
        usort($rows, static function (array $a, array $b): int {
            $rank = static fn (array $x): int => $x['total'] > 0 ? 0 : ($x['last_security_scan_at'] !== null ? 1 : 2);
            $ra = $rank($a); $rb = $rank($b);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            if ($ra === 0) {
                $cmp = [$b['critical'], $b['high'], $b['medium'], $b['low'], $b['total']]
                    <=> [$a['critical'], $a['high'], $a['medium'], $a['low'], $a['total']];
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return strcmp($a['label'], $b['label']);
        });

        $sites = array_map(static fn (array $r): array => [
            'site_id'               => $r['site_id'],
            'label'                 => $r['label'],
            'url'                   => $r['url'],
            'last_security_scan_at' => $r['last_security_scan_at'],
            'counts'                => [
                'critical' => $r['critical'],
                'high'     => $r['high'],
                'medium'   => $r['medium'],
                'low'      => $r['low'],
                'total'    => $r['total'],
            ],
        ], $rows);

        return [
            'summary' => [
                'total_sites'   => $totalSites,
                'scanned_sites' => $scannedSites,
                'sites_at_risk' => $sitesAtRisk,
                'critical'      => $crit,
                'high'          => $high,
                'medium'        => $med,
                'low'           => $low,
            ],
            'sites'        => $sites,
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ];
    }
}
