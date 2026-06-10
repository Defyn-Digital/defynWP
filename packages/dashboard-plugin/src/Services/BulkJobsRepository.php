<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Schema\BulkJobItemsTable;
use Defyn\Dashboard\Schema\BulkJobsTable;

/**
 * P2.9 — CRUD + lifecycle marks for the BulkJob entity (spec § 2.8).
 *
 * Part 1 (this task): createJob / createItems / findByIdForUser /
 * findItemsForJob / findItemForJob. Part 2 (Task 4) adds the markItem*
 * lifecycle methods, refreshJobTimestamps, and the list/filter queries.
 * Task 6 adds findItemsForJobWithResources (detail-view JOIN).
 */
final class BulkJobsRepository
{
    public function createJob(int $userId, string $kind, int $scheduledCount, int $skippedCount, string $now): int
    {
        global $wpdb;
        $wpdb->insert(BulkJobsTable::tableName(), [
            'user_id'         => $userId,
            'kind'            => $kind,
            'scheduled_count' => $scheduledCount,
            'skipped_count'   => $skippedCount,
            'created_at'      => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    /**
     * Single multi-row INSERT + ONE read-back SELECT (guardrail #5) — the
     * query count never scales with pair count. The read-back avoids any
     * assumption about consecutive auto-increment allocation.
     *
     * @param list<array{site_id: int, slug: string}> $pairs
     * @return list<array{site_id: int, slug: string, item_id: int}>
     */
    public function createItems(int $jobId, array $pairs, string $now): array
    {
        if ($pairs === []) {
            return [];
        }

        global $wpdb;
        $table = BulkJobItemsTable::tableName();

        $placeholders = [];
        $values       = [];
        foreach ($pairs as $pair) {
            $placeholders[] = '(%d, %d, %s, %s, %s)';
            $values[]       = $jobId;
            $values[]       = (int) $pair['site_id'];
            $values[]       = (string) $pair['slug'];
            $values[]       = 'queued';
            $values[]       = $now;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL — placeholder list built above; all values flow through prepare().
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (job_id, site_id, resource_slug, state, created_at) VALUES "
                . implode(', ', $placeholders),
            $values
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, site_id, resource_slug FROM {$table} WHERE job_id = %d ORDER BY id ASC",
            $jobId
        ), ARRAY_A);

        return array_map(static fn(array $row) => [
            'site_id' => (int) $row['site_id'],
            'slug'    => (string) $row['resource_slug'],
            'item_id' => (int) $row['id'],
        ], is_array($rows) ? $rows : []);
    }

    /** @return array<string, mixed>|null Null for missing OR foreign jobs (guardrail #7). */
    public function findByIdForUser(int $jobId, int $userId): ?array
    {
        global $wpdb;
        $table = BulkJobsTable::tableName();
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
            $jobId,
            $userId
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function findItemsForJob(int $jobId): array
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE job_id = %d ORDER BY id ASC",
            $jobId
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed>|null Scoped to the job — foreign items return null. */
    public function findItemForJob(int $jobId, int $itemId): ?array
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND job_id = %d",
            $itemId,
            $jobId
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    // ─── Lifecycle marks (each triggers refreshJobTimestamps — guardrail #8) ──

    /** queued → started. Guarded — 409-retry re-entries (already started) are no-ops. */
    public function markItemStarted(int $itemId, string $now): void
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET state = 'started', started_at = %s WHERE id = %d AND state = 'queued'",
            $now,
            $itemId
        ));
        $this->refreshForItem($itemId, $now);
    }

    public function markItemSucceeded(int $itemId, string $now): void
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET state = 'succeeded', completed_at = %s
             WHERE id = %d AND state IN ('queued', 'started')",
            $now,
            $itemId
        ));
        $this->refreshForItem($itemId, $now);
    }

    public function markItemFailed(int $itemId, string $now, string $errorMessage): void
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET state = 'failed', completed_at = %s, error_message = %s
             WHERE id = %d AND state IN ('queued', 'started')",
            $now,
            mb_substr($errorMessage, 0, 1000),
            $itemId
        ));
        $this->refreshForItem($itemId, $now);
    }

    /** Guardrail #6 — cancel is only legal from `queued`; anything else is a silent no-op. */
    public function markItemCancelled(int $itemId, string $now): void
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET state = 'cancelled', completed_at = %s WHERE id = %d AND state = 'queued'",
            $now,
            $itemId
        ));
        $this->refreshForItem($itemId, $now);
    }

    /** failed → queued; clears error + timestamps (spec § 2.4). */
    public function resetItemForRetry(int $itemId, string $now): void
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET state = 'queued', error_message = NULL, started_at = NULL, completed_at = NULL
             WHERE id = %d AND state = 'failed'",
            $itemId
        ));
        $this->refreshForItem($itemId, $now);
    }

    /**
     * Maybe-touch job-level started_at / completed_at based on current item
     * states (guardrail #8). Three guarded statements — each a no-op when the
     * condition doesn't hold, so calling this after every mark is cheap.
     */
    public function refreshJobTimestamps(int $jobId, string $now): void
    {
        global $wpdb;
        $jobs  = BulkJobsTable::tableName();
        $items = BulkJobItemsTable::tableName();

        // started_at — first time any item moves beyond `queued` toward
        // execution (cancelled-only movement doesn't count as "started").
        $wpdb->query($wpdb->prepare(
            "UPDATE {$jobs} SET started_at = %s
             WHERE id = %d AND started_at IS NULL
               AND EXISTS (
                   SELECT 1 FROM {$items}
                   WHERE job_id = %d AND state IN ('started', 'succeeded', 'failed')
               )",
            $now,
            $jobId,
            $jobId
        ));

        // completed_at — set once nothing is queued/started any more…
        $wpdb->query($wpdb->prepare(
            "UPDATE {$jobs} SET completed_at = %s
             WHERE id = %d AND completed_at IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM {$items}
                   WHERE job_id = %d AND state IN ('queued', 'started')
               )",
            $now,
            $jobId,
            $jobId
        ));

        // …and cleared again when a retry re-queues an item.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$jobs} SET completed_at = NULL
             WHERE id = %d AND completed_at IS NOT NULL
               AND EXISTS (
                   SELECT 1 FROM {$items}
                   WHERE job_id = %d AND state IN ('queued', 'started')
               )",
            $jobId,
            $jobId
        ));
    }

    /**
     * Jobs newest-first. $statusFilter: 'active' (has queued/started items —
     * job-level queued|in_progress) | 'completed' (all terminal — job-level
     * completed|partial) | null (no filter).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllForUser(int $userId, ?string $statusFilter, int $limit, int $offset): array
    {
        global $wpdb;
        $jobs      = BulkJobsTable::tableName();
        $statusSql = $this->statusFilterSql($statusFilter);

        // phpcs:ignore WordPress.DB.PreparedSQL — $statusSql is a fixed fragment chosen below.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT j.* FROM {$jobs} j
             WHERE j.user_id = %d {$statusSql}
             ORDER BY j.created_at DESC, j.id DESC
             LIMIT %d OFFSET %d",
            $userId,
            $limit,
            $offset
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function countAllForUser(int $userId, ?string $statusFilter): int
    {
        global $wpdb;
        $jobs      = BulkJobsTable::tableName();
        $statusSql = $this->statusFilterSql($statusFilter);

        // phpcs:ignore WordPress.DB.PreparedSQL — $statusSql is a fixed fragment chosen below.
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$jobs} j WHERE j.user_id = %d {$statusSql}",
            $userId
        ));
    }

    /**
     * Queued items with the exact fields the Cancel controller needs to call
     * as_unschedule_action with the schedule-time 4-tuple (guardrail #4).
     *
     * @return list<array{item_id: int, site_id: int, slug: string}>
     */
    public function findQueuedItemsForJob(int $jobId): array
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT id, site_id, resource_slug FROM {$table}
             WHERE job_id = %d AND state = 'queued' ORDER BY id ASC",
            $jobId
        ), ARRAY_A);

        return array_map(static fn(array $row) => [
            'item_id' => (int) $row['id'],
            'site_id' => (int) $row['site_id'],
            'slug'    => (string) $row['resource_slug'],
        ], is_array($rows) ? $rows : []);
    }

    public function countItemsByStateForJob(int $jobId, string $state): int
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE job_id = %d AND state = %s",
            $jobId,
            $state
        ));
    }

    /**
     * ONE grouped query for the list page — avoids N+1 across page rows.
     *
     * @param list<int> $jobIds
     * @return array<int, array{queued: int, started: int, succeeded: int, failed: int, cancelled: int}>
     */
    public function countsByStateForJobs(array $jobIds): array
    {
        if ($jobIds === []) {
            return [];
        }

        global $wpdb;
        $table        = BulkJobItemsTable::tableName();
        $placeholders = implode(', ', array_fill(0, count($jobIds), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQL — placeholder list built above.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT job_id, state, COUNT(*) AS n FROM {$table}
             WHERE job_id IN ({$placeholders}) GROUP BY job_id, state",
            $jobIds
        ), ARRAY_A);

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $jobId = (int) $row['job_id'];
            if (!isset($out[$jobId])) {
                $out[$jobId] = BulkJobAggregator::emptyCounts();
            }
            $state = (string) $row['state'];
            if (array_key_exists($state, $out[$jobId])) {
                $out[$jobId][$state] = (int) $row['n'];
            }
        }
        return $out;
    }

    private function statusFilterSql(?string $statusFilter): string
    {
        $items        = BulkJobItemsTable::tableName();
        $activeExists = "EXISTS (SELECT 1 FROM {$items} i WHERE i.job_id = j.id AND i.state IN ('queued', 'started'))";

        if ($statusFilter === 'active') {
            return "AND {$activeExists}";
        }
        if ($statusFilter === 'completed') {
            return "AND NOT {$activeExists}";
        }
        return '';
    }

    private function refreshForItem(int $itemId, string $now): void
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        $jobId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT job_id FROM {$table} WHERE id = %d",
            $itemId
        ));
        if ($jobId > 0) {
            $this->refreshJobTimestamps($jobId, $now);
        }
    }
}
