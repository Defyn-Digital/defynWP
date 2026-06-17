<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\SiteAnalytics;
use Defyn\Dashboard\Schema\SiteAnalyticsTable;

/**
 * P6.2 — store/read GA4 calendar-month snapshots. upsert is delete-then-insert
 * keyed on the logical tuple (site_id, period_start, period_end), so a re-sync
 * of the same month replaces rather than duplicates.
 */
final class SiteAnalyticsRepository
{
    /** @param array{sessions:?int,users:?int,pageviews:?int,avg_engagement:?float,top_pages:array,channels:array} $data */
    public function upsertForSiteAndPeriod(int $siteId, string $periodStart, string $periodEnd, array $data, string $fetchedAt, string $now): int
    {
        global $wpdb;
        $table = SiteAnalyticsTable::tableName();
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE site_id = %d AND period_start = %s AND period_end = %s",
            $siteId, $periodStart, $periodEnd
        ));
        $wpdb->insert($table, [
            'site_id'              => $siteId,
            'period_start'         => $periodStart,
            'period_end'           => $periodEnd,
            'sessions'             => $data['sessions'] ?? null,
            'total_users'          => $data['users'] ?? null,
            'screen_page_views'    => $data['pageviews'] ?? null,
            'avg_session_duration' => $data['avg_engagement'] ?? null,
            'top_pages'            => json_encode(array_values($data['top_pages'] ?? [])),
            'channels'             => json_encode(array_values($data['channels'] ?? [])),
            'fetched_at'           => $fetchedAt,
            'created_at'           => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    public function findForSiteAndMonth(int $siteId, string $periodStart): ?SiteAnalytics
    {
        global $wpdb;
        $table = SiteAnalyticsTable::tableName();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE site_id = %d AND period_start = %s LIMIT 1",
            $siteId, $periodStart
        ), ARRAY_A);
        return $row ? SiteAnalytics::fromRow($row) : null;
    }

    public function latestForSite(int $siteId): ?SiteAnalytics
    {
        global $wpdb;
        $table = SiteAnalyticsTable::tableName();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE site_id = %d ORDER BY period_start DESC, id DESC LIMIT 1",
            $siteId
        ), ARRAY_A);
        return $row ? SiteAnalytics::fromRow($row) : null;
    }
}
