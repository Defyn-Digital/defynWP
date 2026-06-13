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
 * P2.9 — POST /defyn/v1/jobs/{id}/cancel (spec § 2.3).
 *
 * Unschedules every still-queued item's AS action (exact 4-tuple match —
 * guardrail #4) and marks the items cancelled. Items already `started`
 * can't be interrupted mid-upgrade; they keep running and are surfaced
 * via still_running_count. Synchronous + idempotent — always 200
 * (guardrail #13).
 */
final class JobsCancelController
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

            $hook = $job['kind'] === 'theme_update'
                ? UpdateSiteTheme::HOOK
                : UpdateSitePlugin::HOOK;

            $now    = gmdate('Y-m-d H:i:s');
            $queued = $this->jobs->findQueuedItemsForJob($jobId);

            foreach ($queued as $item) {
                // Guardrail #4 — exact schedule-time 4-tuple + 'defyn' group.
                as_unschedule_action($hook, [$item['site_id'], $item['slug'], 0, $item['item_id']], 'defyn');
                $this->jobs->markItemCancelled($item['item_id'], $now);
            }

            return new WP_REST_Response([
                'cancelled_count'     => count($queued),
                'still_running_count' => $this->jobs->countItemsByStateForJob($jobId, 'started'),
                'cancelled_at'        => $now,
            ], 200);
        } finally {
            ob_end_clean();
        }
    }
}
