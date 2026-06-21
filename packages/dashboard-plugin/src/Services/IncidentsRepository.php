<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables: direct queries are required and results are request-scoped.

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Schema\IncidentsTable;
use Defyn\Dashboard\Schema\SitesTable;

/**
 * P3.1 — CRUD for the Incident entity (spec § 1).
 *
 * All timestamps are passed in by callers (UTC strings) — this repository
 * does not generate them. Guardrail 5 (single open incident per site) is
 * the caller's responsibility; findOpenForSite uses ORDER BY … LIMIT 1
 * as a defensive tie-breaker only.
 */
final class IncidentsRepository
{
    public function findOpenForSite(int $siteId): ?Incident
    {
        global $wpdb;
        $t = IncidentsTable::tableName();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$t}` WHERE site_id = %d AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1", $siteId),
            ARRAY_A
        );
        return $row ? Incident::fromRow($row) : null;
    }

    public function open(int $siteId, string $startedAt, string $error): int
    {
        global $wpdb;
        $wpdb->insert(IncidentsTable::tableName(), [
            'site_id'    => $siteId,
            'started_at' => $startedAt,
            'last_error' => $error,
            'created_at' => $startedAt,
        ], ['%d', '%s', '%s', '%s']);
        return (int) $wpdb->insert_id;
    }

    public function close(int $incidentId, string $endedAt, int $durationSeconds): void
    {
        global $wpdb;
        $wpdb->update(
            IncidentsTable::tableName(),
            ['ended_at' => $endedAt, 'duration_seconds' => $durationSeconds],
            ['id' => $incidentId],
            ['%s', '%d'],
            ['%d']
        );
    }

    public function markDownAlertSent(int $id, string $at): void
    {
        global $wpdb;
        $wpdb->update(IncidentsTable::tableName(), ['down_alert_sent_at' => $at], ['id' => $id], ['%s'], ['%d']);
    }

    public function markUpAlertSent(int $id, string $at): void
    {
        global $wpdb;
        $wpdb->update(IncidentsTable::tableName(), ['up_alert_sent_at' => $at], ['id' => $id], ['%s'], ['%d']);
    }

    /** @return Incident[] */
    public function findForSite(int $siteId, int $limit, int $offset): array
    {
        global $wpdb;
        $t = IncidentsTable::tableName();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$t}` WHERE site_id = %d ORDER BY started_at DESC LIMIT %d OFFSET %d",
                $siteId,
                $limit,
                $offset
            ),
            ARRAY_A
        ) ?: [];
        return array_map([Incident::class, 'fromRow'], $rows);
    }

    /**
     * P3.2 — all incidents for the user's sites overlapping [since, now]:
     * still-open OR ended on/after $sinceUtc. One query; grouped by site in PHP.
     *
     * @return array<int,array{site_id:int,started_at:string,ended_at:?string}>
     */
    public function findForUserSince(int $userId, string $sinceUtc): array
    {
        global $wpdb;
        $i = IncidentsTable::tableName();
        $s = SitesTable::tableName();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.site_id AS site_id, i.started_at AS started_at, i.ended_at AS ended_at
             FROM `{$i}` i INNER JOIN `{$s}` s ON s.id = i.site_id
             WHERE s.user_id = %d AND (i.ended_at IS NULL OR i.ended_at >= %s)
             ORDER BY i.started_at ASC",
            $userId,
            $sinceUtc
        ), ARRAY_A) ?: [];

        return array_map(static fn ($r) => [
            'site_id'    => (int) $r['site_id'],
            'started_at' => (string) $r['started_at'],
            'ended_at'   => $r['ended_at'] !== null ? (string) $r['ended_at'] : null,
        ], $rows);
    }

    /**
     * P5.1 — incidents overlapping [fromUtc, toUtc] for a single site.
     * Overlap predicate: started_at <= toUtc AND (ended_at IS NULL OR ended_at >= fromUtc).
     *
     * @return list<Incident> newest-started first
     */
    public function findForSiteInRange(int $siteId, string $fromUtc, string $toUtc): array
    {
        global $wpdb;
        $table = IncidentsTable::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table}`
             WHERE site_id = %d AND started_at <= %s AND (ended_at IS NULL OR ended_at >= %s)
             ORDER BY started_at DESC",
            $siteId, $toUtc, $fromUtc
        ), ARRAY_A) ?: [];

        return array_map([Incident::class, 'fromRow'], $rows);
    }

    /** @return array<int,array{site_id:int,site_label:string,started_at:string}> */
    public function findOpenForUser(int $userId): array
    {
        global $wpdb;
        $i = IncidentsTable::tableName();
        $s = SitesTable::tableName();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.site_id AS site_id, s.label AS site_label, i.started_at AS started_at
             FROM `{$i}` i INNER JOIN `{$s}` s ON s.id = i.site_id
             WHERE s.user_id = %d AND i.ended_at IS NULL
             ORDER BY i.started_at ASC",
            $userId
        ), ARRAY_A) ?: [];
        return array_map(static fn ($r) => [
            'site_id'    => (int) $r['site_id'],
            'site_label' => (string) $r['site_label'],
            'started_at' => (string) $r['started_at'],
        ], $rows);
    }
}
