<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Schema\SiteBrokenLinksTable;
use Defyn\Dashboard\Schema\SitesTable;

/**
 * Data-access layer for wp_defyn_site_broken_links.
 *
 * Uses SELECT-then-update/insert (not ON DUPLICATE KEY UPDATE) to:
 *   - keep first_detected_at immutable on update
 *   - handle nullable columns cleanly via $wpdb->insert / $wpdb->update
 *     (both emit real SQL NULL for null values without needing explicit $format)
 */
final class BrokenLinksRepository
{
    /**
     * Insert a new broken-link finding or update the mutable fields of an existing one.
     *
     * The unique identity for a finding is (site_id, url_hash, source_hash).
     * first_detected_at is set only on INSERT and never touched on UPDATE.
     *
     * @param array{
     *   url: string,
     *   source_url: string,
     *   severity: string,
     *   reason: string,
     *   link_type: string,
     *   status_code?: int|null,
     *   source_title?: string|null,
     *   anchor_text?: string|null,
     * } $finding
     */
    public function upsertForSite(int $siteId, array $finding, string $scanAt): void
    {
        global $wpdb;
        $t       = SiteBrokenLinksTable::tableName();
        $urlHash = sha1((string) $finding['url']);
        $srcHash = sha1((string) $finding['source_url']);

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$t} WHERE site_id=%d AND url_hash=%s AND source_hash=%s",
            $siteId,
            $urlHash,
            $srcHash
        ));

        $mutable = [
            'status_code'      => $finding['status_code'] ?? null,
            'severity'         => (string) $finding['severity'],
            'reason'           => (string) $finding['reason'],
            'link_type'        => (string) $finding['link_type'],
            'source_title'     => $finding['source_title'] ?? null,
            'anchor_text'      => $finding['anchor_text'] ?? null,
            'last_detected_at' => $scanAt,
        ];

        if ($id !== null) {
            $wpdb->update($t, $mutable, ['id' => (int) $id]);
        } else {
            $wpdb->insert($t, array_merge($mutable, [
                'site_id'           => $siteId,
                'url'               => (string) $finding['url'],
                'url_hash'          => $urlHash,
                'source_url'        => (string) $finding['source_url'],
                'source_hash'       => $srcHash,
                'first_detected_at' => $scanAt,
            ]));
        }
    }

    /**
     * Delete all rows for a site whose last_detected_at is strictly older than $scanAt.
     *
     * Returns the number of rows deleted.
     */
    public function pruneStaleForSite(int $siteId, string $scanAt): int
    {
        global $wpdb;
        $t = SiteBrokenLinksTable::tableName();

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$t} WHERE site_id=%d AND last_detected_at < %s",
            $siteId,
            $scanAt
        ));

        return (int) $result;
    }

    /**
     * Return all broken-link rows for a site, broken severity first, then newest first.
     *
     * @return list<array{
     *   id: int,
     *   site_id: int,
     *   url: string,
     *   url_hash: string,
     *   source_url: string,
     *   source_hash: string,
     *   source_title: string|null,
     *   anchor_text: string|null,
     *   status_code: int|null,
     *   severity: string,
     *   reason: string,
     *   link_type: string,
     *   first_detected_at: string,
     *   last_detected_at: string,
     * }>
     */
    public function findForSite(int $siteId): array
    {
        global $wpdb;
        $t = SiteBrokenLinksTable::tableName();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t}
              WHERE site_id=%d
              ORDER BY (severity='broken') DESC, last_detected_at DESC",
            $siteId
        ), ARRAY_A) ?: [];

        return array_map([self::class, 'castRow'], $rows);
    }

    /**
     * Return aggregate counts of broken-link rows for a site.
     *
     * @return array{broken: int, warning: int, total: int, internal: int, external: int}
     */
    public function countsForSite(int $siteId): array
    {
        global $wpdb;
        $t = SiteBrokenLinksTable::tableName();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(severity='broken'),  0) AS broken,
                COALESCE(SUM(severity='warning'), 0) AS warning,
                COUNT(*)                             AS total,
                COALESCE(SUM(link_type='internal'),0) AS internal,
                COALESCE(SUM(link_type='external'),0) AS external
              FROM {$t}
             WHERE site_id=%d",
            $siteId
        ), ARRAY_A);

        if ($row === null) {
            return ['broken' => 0, 'warning' => 0, 'total' => 0, 'internal' => 0, 'external' => 0];
        }

        return [
            'broken'   => (int) $row['broken'],
            'warning'  => (int) $row['warning'],
            'total'    => (int) $row['total'],
            'internal' => (int) $row['internal'],
            'external' => (int) $row['external'],
        ];
    }

    /**
     * Count the number of distinct sites (owned by $userId) that have at least one broken-severity link.
     */
    public function countSitesWithBrokenLinksForUser(int $userId): int
    {
        global $wpdb;
        $bl    = SiteBrokenLinksTable::tableName();
        $sites = SitesTable::tableName();

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT bl.site_id)
               FROM {$bl} bl
               JOIN {$sites} s ON s.id = bl.site_id
              WHERE s.user_id=%d
                AND bl.severity='broken'",
            $userId
        ));

        return (int) $count;
    }

    /**
     * Return the top $limit broken-link rows for a site (broken first, then newest first).
     * Used by the report service to embed a summary section.
     *
     * @return list<array{
     *   id: int,
     *   site_id: int,
     *   url: string,
     *   url_hash: string,
     *   source_url: string,
     *   source_hash: string,
     *   source_title: string|null,
     *   anchor_text: string|null,
     *   status_code: int|null,
     *   severity: string,
     *   reason: string,
     *   link_type: string,
     *   first_detected_at: string,
     *   last_detected_at: string,
     * }>
     */
    public function findTopForReport(int $siteId, int $limit): array
    {
        global $wpdb;
        $t = SiteBrokenLinksTable::tableName();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t}
              WHERE site_id=%d
              ORDER BY (severity='broken') DESC, last_detected_at DESC
              LIMIT %d",
            $siteId,
            $limit
        ), ARRAY_A) ?: [];

        return array_map([self::class, 'castRow'], $rows);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Cast a raw $wpdb ARRAY_A row to typed PHP values.
     *
     * $wpdb returns everything as strings; callers expect id/site_id as int
     * and status_code as ?int (null stays null).
     *
     * @param array<string, string|null> $row
     * @return array<string, mixed>
     */
    private static function castRow(array $row): array
    {
        $row['id']          = (int) $row['id'];
        $row['site_id']     = (int) $row['site_id'];
        $row['status_code'] = $row['status_code'] !== null ? (int) $row['status_code'] : null;
        return $row;
    }
}
