<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\UpdateSitePlugin;
use Defyn\Dashboard\Jobs\UpdateSiteTheme;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\BulkJobsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.9 — POST /defyn/v1/jobs/{id}/retry-failed (spec § 2.5).
 *
 * Bulk variant of the per-item retry: ONE request re-queues every failed
 * item in the job. 202 when retried_count > 0; 200 no-op when the job has
 * no failed items (guardrail #13).
 */
final class JobsRetryFailedController
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
                return ErrorResponse::create(404, 'jobs.not_found', 'Job not found.');
            }

            $failed = array_values(array_filter(
                $this->jobs->findItemsForJob($jobId),
                static fn(array $item): bool => (string) $item['state'] === 'failed'
            ));

            $now  = gmdate('Y-m-d H:i:s');
            $hook = $job['kind'] === 'theme_update'
                ? UpdateSiteTheme::HOOK
                : UpdateSitePlugin::HOOK;

            $retriedIds = [];
            foreach ($failed as $item) {
                $itemId = (int) $item['id'];
                $this->jobs->resetItemForRetry($itemId, $now);
                as_schedule_single_action(
                    time(),
                    $hook,
                    [(int) $item['site_id'], (string) $item['resource_slug'], 0, $itemId],
                    'defyn'
                );
                $retriedIds[] = $itemId;
            }

            return new WP_REST_Response([
                'retried_count'    => count($retriedIds),
                'retried_item_ids' => $retriedIds,
                'scheduled_at'     => $now,
            ], count($retriedIds) > 0 ? 202 : 200);
        } finally {
            ob_end_clean();
        }
    }
}
