<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\BulkJobAggregator;
use Defyn\Dashboard\Services\BulkJobsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.9 — GET /defyn/v1/jobs/{id} (spec § 2.2).
 *
 * Job header (via JobsListController::presentJob — shared shape) + items
 * with response-time resource resolution. Missing inventory rows fall back
 * to resource_slug / null versions; missing sites to "Site #N".
 */
final class JobsDetailController
{
    public function __construct(
        private readonly BulkJobsRepository $jobs = new BulkJobsRepository(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Defensive STDOUT guard — P2.2 plan-bug #4 carry-forward.
        ob_start();
        try {
            $userId = (int) $request->get_param('_authenticated_user_id');
            $jobId  = (int) $request['id'];

            $job = $this->jobs->findByIdForUser($jobId, $userId);
            if ($job === null) {
                // Guardrail #7/#14 — 404 for missing AND foreign jobs.
                return ErrorResponse::create(404, 'jobs.not_found', 'Job not found.');
            }

            $rows   = $this->jobs->findItemsForJobWithResources($jobId, (string) $job['kind']);
            $counts = BulkJobAggregator::countsByState($rows);

            $items = array_map(static fn(array $row): array => [
                'id'              => (int) $row['id'],
                'site_id'         => (int) $row['site_id'],
                'site_label'      => $row['site_label'] !== null
                    ? (string) $row['site_label']
                    : 'Site #' . (int) $row['site_id'],
                'resource_slug'   => (string) $row['resource_slug'],
                'resource_name'   => $row['resource_name'] !== null
                    ? (string) $row['resource_name']
                    : (string) $row['resource_slug'],
                'current_version' => $row['resource_current_version'] !== null
                    ? (string) $row['resource_current_version'] : null,
                'target_version'  => $row['resource_target_version'] !== null
                    ? (string) $row['resource_target_version'] : null,
                'state'           => (string) $row['state'],
                'error_message'   => $row['error_message'] !== null ? (string) $row['error_message'] : null,
                'started_at'      => $row['started_at'],
                'completed_at'    => $row['completed_at'],
                'created_at'      => (string) $row['created_at'],
            ], $rows);

            return new WP_REST_Response([
                'job'          => JobsListController::presentJob($job, $counts),
                'items'        => array_values($items),
                'generated_at' => gmdate('Y-m-d H:i:s'),
            ], 200);
        } finally {
            ob_end_clean();
        }
    }
}
