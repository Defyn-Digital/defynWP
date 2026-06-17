<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\SitePerformance;
use Defyn\Dashboard\Schema\SitePerformanceTable;
use Defyn\Dashboard\Schema\SitesTable;

final class SitePerformanceRepository
{
    /**
     * @param array{score:int,lcp_ms:?int,cls:?float,inp_ms:?int}|null $mobile
     * @param array{score:int,lcp_ms:?int,cls:?float,inp_ms:?int}|null $desktop
     */
    public function store(int $siteId, ?array $mobile, ?array $desktop, string $fetchedAt, string $now): int
    {
        global $wpdb;
        $wpdb->insert(SitePerformanceTable::tableName(), [
            'site_id'        => $siteId,
            'mobile_score'   => $mobile['score']  ?? null,
            'mobile_lcp_ms'  => $mobile['lcp_ms'] ?? null,
            'mobile_cls'     => $mobile['cls']    ?? null,
            'mobile_inp_ms'  => $mobile['inp_ms'] ?? null,
            'desktop_score'  => $desktop['score']  ?? null,
            'desktop_lcp_ms' => $desktop['lcp_ms'] ?? null,
            'desktop_cls'    => $desktop['cls']    ?? null,
            'desktop_inp_ms' => $desktop['inp_ms'] ?? null,
            'fetched_at'     => $fetchedAt,
            'created_at'     => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    public function latestForSite(int $siteId): ?SitePerformance
    {
        global $wpdb;
        $t = SitePerformanceTable::tableName();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t} WHERE site_id = %d ORDER BY fetched_at DESC, id DESC LIMIT 1", $siteId
        ), ARRAY_A);
        return $row ? SitePerformance::fromRow($row) : null;
    }

    /** @return SitePerformance[] oldest→newest */
    public function findForSiteInRange(int $siteId, string $fromUtc, string $toUtc): array
    {
        global $wpdb;
        $t = SitePerformanceTable::tableName();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE site_id = %d AND fetched_at BETWEEN %s AND %s ORDER BY fetched_at ASC, id ASC",
            $siteId, $fromUtc, $toUtc
        ), ARRAY_A) ?: [];
        return array_map([SitePerformance::class, 'fromRow'], $rows);
    }

    /**
     * P6.3 — fleet rollup: every site owned by $userId LEFT JOIN its latest
     * performance snapshot (one row per site; nulls when never measured).
     * ONLY_FULL_GROUP_BY-safe — no GROUP BY; the correlated id subquery picks
     * exactly one row per site (newest fetched_at, id-tiebroken).
     *
     * @return list<array{site_id:int,label:string,url:string,mobile_score:?int,desktop_score:?int,mobile_lcp_ms:?int,fetched_at:?string}>
     */
    public function findFleetForUser(int $userId): array
    {
        global $wpdb;
        $perf  = SitePerformanceTable::tableName();
        $sites = SitesTable::tableName();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id AS site_id, s.label AS label, s.url AS url,
                    p.mobile_score, p.desktop_score, p.mobile_lcp_ms, p.fetched_at
               FROM {$sites} s
               LEFT JOIN {$perf} p
                 ON p.site_id = s.id
                AND p.id = (
                    SELECT p2.id FROM {$perf} p2
                     WHERE p2.site_id = s.id
                     ORDER BY p2.fetched_at DESC, p2.id DESC
                     LIMIT 1
                )
              WHERE s.user_id = %d
              ORDER BY s.id ASC",
            $userId
        ), ARRAY_A) ?: [];

        return array_map(static fn (array $r): array => [
            'site_id'       => (int) $r['site_id'],
            'label'         => (string) $r['label'],
            'url'           => (string) $r['url'],
            'mobile_score'  => $r['mobile_score']  !== null ? (int) $r['mobile_score']  : null,
            'desktop_score' => $r['desktop_score'] !== null ? (int) $r['desktop_score'] : null,
            'mobile_lcp_ms' => $r['mobile_lcp_ms'] !== null ? (int) $r['mobile_lcp_ms'] : null,
            'fetched_at'    => $r['fetched_at']    !== null ? (string) $r['fetched_at']  : null,
        ], $rows);
    }
}
