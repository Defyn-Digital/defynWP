<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\BulkJobAggregator;
use Defyn\Dashboard\Services\BulkJobsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.9 — GET /defyn/v1/jobs (spec § 2.1).
 *
 * Paginated, status-filterable list of the operator's bulk jobs with
 * derived per-state counts + job-level state. Counts come from ONE
 * grouped query (countsByStateForJobs) — no per-row item loading.
 */
final class JobsListController
{
    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE     = 100;

    public function __construct(
        private readonly BulkJobsRepository $jobs = new BulkJobsRepository(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Defensive STDOUT guard — P2.2 plan-bug #4 carry-forward.
        ob_start();
        try {
            $userId  = (int) $request->get_param('_authenticated_user_id');
            $page    = max(1, (int) ($request->get_param('page') ?: 1));
            $perPage = (int) ($request->get_param('per_page') ?: self::DEFAULT_PER_PAGE);
            $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
            $status  = (string) ($request->get_param('status') ?: 'all');
            if (!in_array($status, ['active', 'completed', 'all'], true)) {
                $status = 'all';
            }
            $filter = $status === 'all' ? null : $status;
            $offset = ($page - 1) * $perPage;

            $rows  = $this->jobs->findAllForUser($userId, $filter, $perPage, $offset);
            $total = $this->jobs->countAllForUser($userId, $filter);

            $countsByJob = $this->jobs->countsByStateForJobs(
                array_map(static fn(array $r): int => (int) $r['id'], $rows)
            );

            $jobs = array_map(static function (array $row) use ($countsByJob): array {
                $counts = $countsByJob[(int) $row['id']] ?? BulkJobAggregator::emptyCounts();
                return self::presentJob($row, $counts);
            }, $rows);

            return new WP_REST_Response([
                'jobs'         => array_values($jobs),
                'total'        => $total,
                'page'         => $page,
                'per_page'     => $perPage,
                'generated_at' => gmdate('Y-m-d H:i:s'),
            ], 200);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Shared job JSON shape — also used by JobsDetailController (DRY).
     *
     * @param array<string, mixed> $row Raw wp_defyn_bulk_jobs row.
     * @param array{queued: int, started: int, succeeded: int, failed: int, cancelled: int} $counts
     * @return array<string, mixed>
     */
    public static function presentJob(array $row, array $counts): array
    {
        return [
            'id'              => (int) $row['id'],
            'kind'            => (string) $row['kind'],
            'scheduled_count' => (int) $row['scheduled_count'],
            'skipped_count'   => (int) $row['skipped_count'],
            'succeeded_count' => $counts['succeeded'],
            'failed_count'    => $counts['failed'],
            'cancelled_count' => $counts['cancelled'],
            'queued_count'    => $counts['queued'],
            'started_count'   => $counts['started'],
            'state'           => BulkJobAggregator::deriveJobStateFromCounts($counts),
            'started_at'      => $row['started_at'],
            'completed_at'    => $row['completed_at'],
            'created_at'      => (string) $row['created_at'],
        ];
    }
}
