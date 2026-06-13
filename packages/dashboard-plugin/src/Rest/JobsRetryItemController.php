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
 * P2.9 — POST /defyn/v1/jobs/{id}/items/{item_id}/retry (spec § 2.4).
 *
 * Only `failed` items are retryable. Resets the item to `queued` (clearing
 * error + timestamps; refreshJobTimestamps un-finalizes the job) and
 * re-schedules the kind-appropriate AS hook with the same 4-tuple shape the
 * bulk fan-out uses. 202 — the work is async.
 */
final class JobsRetryItemController
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
            $itemId = (int) $request['item_id'];

            $job = $this->jobs->findByIdForUser($jobId, $userId);
            if ($job === null) {
                return ErrorResponse::create(404, 'jobs.not_found', 'Job not found.');
            }

            $item = $this->jobs->findItemForJob($jobId, $itemId);
            if ($item === null) {
                return ErrorResponse::create(404, 'jobs.item_not_found', 'Job item not found.');
            }
            if ((string) $item['state'] !== 'failed') {
                return ErrorResponse::create(400, 'jobs.item_not_retryable', 'Only failed items can be retried.');
            }

            $now = gmdate('Y-m-d H:i:s');
            $this->jobs->resetItemForRetry($itemId, $now);

            $hook = $job['kind'] === 'theme_update'
                ? UpdateSiteTheme::HOOK
                : UpdateSitePlugin::HOOK;

            as_schedule_single_action(
                time(),
                $hook,
                [(int) $item['site_id'], (string) $item['resource_slug'], 0, $itemId],
                'defyn'
            );

            return new WP_REST_Response([
                'item_id'      => $itemId,
                'scheduled_at' => $now,
            ], 202);
        } finally {
            ob_end_clean();
        }
    }
}
