<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables: direct queries are required and results are request-scoped.

use Defyn\Dashboard\Schema\DismissedVulnerabilitiesTable;

/**
 * P4.3b — the dismissal overlay. Stores per-site accepted/ignored findings keyed by
 * the stable fingerprint (site_id, type, slug, source_id). Read by the per-site panel,
 * the fleet rollup, and the scan alert-diff to exclude dismissed findings. The scan
 * snapshot table is never touched here.
 */
final class DismissedVulnerabilitiesRepository
{
    /** Idempotent: a repeat dismiss of the same fingerprint refreshes the timestamp, never duplicates. */
    public function dismiss(int $siteId, string $type, string $slug, string $sourceId, int $userId, string $now): void
    {
        global $wpdb;
        $table = DismissedVulnerabilitiesTable::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (site_id, type, slug, source_id, dismissed_by, dismissed_at)
             VALUES (%d, %s, %s, %s, %d, %s)
             ON DUPLICATE KEY UPDATE dismissed_by = VALUES(dismissed_by), dismissed_at = VALUES(dismissed_at)",
            $siteId,
            $type,
            $slug,
            $sourceId,
            $userId,
            $now
        ));
    }

    /** Idempotent: deleting a non-existent row is a no-op. */
    public function restore(int $siteId, string $type, string $slug, string $sourceId): void
    {
        global $wpdb;
        $wpdb->delete(
            DismissedVulnerabilitiesTable::tableName(),
            ['site_id' => $siteId, 'type' => $type, 'slug' => $slug, 'source_id' => $sourceId],
            ['%d', '%s', '%s', '%s']
        );
    }

    /**
     * @return array<string,true> keyed by "type|slug|source_id" for O(1) membership tests.
     */
    public function findFingerprintsForSite(int $siteId): array
    {
        global $wpdb;
        $table = DismissedVulnerabilitiesTable::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT type, slug, source_id FROM {$table} WHERE site_id = %d", $siteId),
            ARRAY_A
        );
        $out = [];
        foreach ($rows ?: [] as $r) {
            $out[$r['type'] . '|' . $r['slug'] . '|' . $r['source_id']] = true;
        }
        return $out;
    }
}
