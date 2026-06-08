<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.6 — POST /defyn/v1/overview/sync-all.
 *
 * Fan-outs the existing P2.1 `defyn_sync_site` Action Scheduler job for
 * every site owned by the authenticated operator. Logs ONE fleet-scoped
 * `overview.sync_all_requested` activity event with the full `site_ids[]`
 * array in `details`. Read-side action — no inventory writes from this
 * endpoint itself; per-site `site.synced` / `*.inventory.synced` triplets
 * surface naturally from each SyncSite execution.
 *
 * Spec: docs/superpowers/specs/2026-06-08-p2-6-sync-all-sites-design.md § 2
 */
final class OverviewSyncAllController
{
    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly ActivityLogger $logger = new ActivityLogger(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Defensive STDOUT guard — carry-forward from P2.2 plan-bug #4.
        // as_schedule_single_action itself doesn't echo, but some upstream
        // plugins hook action_scheduler_pre_run_action and DO occasionally
        // echo on the synchronous scheduling path. Same pattern as
        // PluginUpdateController + CoreUpdateController.
        ob_start();
        try {
            $userId = (int) $request->get_param('_authenticated_user_id');
            $sites  = $this->sites->findAllForUser($userId);
            $ids    = array_map(static fn($s) => $s->id, $sites);

            foreach ($ids as $id) {
                as_schedule_single_action(time(), 'defyn_sync_site', [$id], 'defyn');
            }

            if (count($ids) > 0) {
                $this->logger->log(
                    $userId,
                    null,                                // fleet-scoped — plan-bug trap #3
                    'overview.sync_all_requested',       // exact string — plan-bug trap #2
                    [
                        'scheduled_count' => count($ids),
                        'site_ids'        => array_values($ids),
                    ]
                );
            }

            return new WP_REST_Response(
                [
                    'scheduled_count' => count($ids),
                    'site_ids'        => array_values($ids),
                    'scheduled_at'    => gmdate('Y-m-d H:i:s'),
                ],
                count($ids) > 0 ? 202 : 200
            );
        } finally {
            ob_end_clean();
        }
    }
}
