<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\SitePerformance;
use Defyn\Dashboard\Schema\SitePerformanceTable;

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
}
