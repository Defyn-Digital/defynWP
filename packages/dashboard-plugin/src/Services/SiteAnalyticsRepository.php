<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\SiteAnalytics;
use Defyn\Dashboard\Schema\SiteAnalyticsTable;
use Defyn\Dashboard\Schema\SitesTable;

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

    /**
     * P6.5 — the most-recent $limit monthly snapshots for a site, returned
     * oldest→newest (for the analytics trend sparkline). Reads accumulated rows —
     * no GA4 call.
     *
     * @return SiteAnalytics[] oldest→newest
     */
    public function findRecentForSite(int $siteId, int $limit): array
    {
        global $wpdb;
        $table = SiteAnalyticsTable::tableName();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE site_id = %d ORDER BY period_start DESC, id DESC LIMIT %d",
            $siteId, $limit
        ), ARRAY_A) ?: [];
        return array_reverse(array_map([SiteAnalytics::class, 'fromRow'], $rows));
    }

    /**
     * P6.3 — fleet rollup: every site owned by $userId LEFT JOIN its latest GA4
     * snapshot (most recent period_start), plus ga4_property_id so the UI can tell
     * "not connected" (no property) from "connected, no data yet". Same
     * ONLY_FULL_GROUP_BY-safe correlated-id form as the performance rollup.
     *
     * @return list<array{site_id:int,label:string,url:string,ga4_property_id:?string,sessions:?int,total_users:?int,screen_page_views:?int,avg_session_duration:?float,period_start:?string,period_end:?string,fetched_at:?string}>
     */
    public function findFleetForUser(int $userId): array
    {
        global $wpdb;
        $analytics = SiteAnalyticsTable::tableName();
        $sites     = SitesTable::tableName();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id AS site_id, s.label AS label, s.url AS url, s.ga4_property_id AS ga4_property_id,
                    a.sessions, a.total_users, a.screen_page_views, a.avg_session_duration,
                    a.period_start, a.period_end, a.fetched_at
               FROM {$sites} s
               LEFT JOIN {$analytics} a
                 ON a.site_id = s.id
                AND a.id = (
                    SELECT a2.id FROM {$analytics} a2
                     WHERE a2.site_id = s.id
                     ORDER BY a2.period_start DESC, a2.id DESC
                     LIMIT 1
                )
              WHERE s.user_id = %d
              ORDER BY s.id ASC",
            $userId
        ), ARRAY_A) ?: [];

        return array_map(static fn (array $r): array => [
            'site_id'              => (int) $r['site_id'],
            'label'                => (string) $r['label'],
            'url'                  => (string) $r['url'],
            'ga4_property_id'      => ($r['ga4_property_id'] !== null && $r['ga4_property_id'] !== '') ? (string) $r['ga4_property_id'] : null,
            'sessions'             => $r['sessions']             !== null ? (int) $r['sessions'] : null,
            'total_users'          => $r['total_users']          !== null ? (int) $r['total_users'] : null,
            'screen_page_views'    => $r['screen_page_views']    !== null ? (int) $r['screen_page_views'] : null,
            'avg_session_duration' => $r['avg_session_duration'] !== null ? (float) $r['avg_session_duration'] : null,
            'period_start'         => $r['period_start']         !== null ? (string) $r['period_start'] : null,
            'period_end'           => $r['period_end']           !== null ? (string) $r['period_end'] : null,
            'fetched_at'           => $r['fetched_at']           !== null ? (string) $r['fetched_at'] : null,
        ], $rows);
    }
}
