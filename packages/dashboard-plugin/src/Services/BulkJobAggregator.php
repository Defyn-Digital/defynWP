<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/**
 * P2.9 — pure-function derived state for bulk jobs (spec § 2.9, guardrail #19).
 *
 * No I/O, no DB, no globals. Used by JobsListController (via grouped counts
 * from BulkJobsRepository::countsByStateForJobs) AND JobsDetailController
 * (via raw item rows).
 *
 * Job-level state semantics (spec § 1):
 *   queued      — all items still queued
 *   in_progress — at least one started, OR any terminal alongside any non-terminal
 *   completed   — all items succeeded (clean win)
 *   partial     — all items terminal but at least one failed or cancelled
 */
final class BulkJobAggregator
{
    /** @return array{queued: int, started: int, succeeded: int, failed: int, cancelled: int} */
    public static function emptyCounts(): array
    {
        return ['queued' => 0, 'started' => 0, 'succeeded' => 0, 'failed' => 0, 'cancelled' => 0];
    }

    /**
     * @param list<array{state: string}> $items
     * @return array{queued: int, started: int, succeeded: int, failed: int, cancelled: int}
     */
    public static function countsByState(array $items): array
    {
        $counts = self::emptyCounts();
        foreach ($items as $item) {
            $state = (string) ($item['state'] ?? '');
            if (array_key_exists($state, $counts)) {
                $counts[$state]++;
            }
        }
        return $counts;
    }

    /**
     * @param array{queued: int, started: int, succeeded: int, failed: int, cancelled: int} $counts
     * @return string 'queued'|'in_progress'|'completed'|'partial'
     */
    public static function deriveJobStateFromCounts(array $counts): string
    {
        $total = array_sum($counts);
        if ($total === 0) {
            return 'queued'; // defensive — jobs are never created without items
        }
        if ($counts['succeeded'] === $total) {
            return 'completed';
        }
        $terminal = $counts['succeeded'] + $counts['failed'] + $counts['cancelled'];
        if ($terminal === $total) {
            return 'partial';
        }
        if ($counts['queued'] === $total) {
            return 'queued';
        }
        return 'in_progress';
    }

    /**
     * @param list<array{state: string}> $items
     * @return string 'queued'|'in_progress'|'completed'|'partial'
     */
    public static function deriveJobState(array $items): string
    {
        return self::deriveJobStateFromCounts(self::countsByState($items));
    }
}
