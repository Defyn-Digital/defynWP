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
}
