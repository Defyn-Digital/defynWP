<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\SecurityScan;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\VulnFeedService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P4.2 — POST /defyn/v1/security/scan-all. Refreshes the vuln feed once, then
 * fan-outs the P4.1 `defyn_security_scan` AS job for every site owned by the
 * operator. Emits ONE fleet-scoped `security.scan_all_requested` activity event
 * (site_id=null) only when scheduled_count > 0. Mirrors OverviewSyncAllController.
 */
final class SecurityScanAllController
{
    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly ActivityLogger $logger = new ActivityLogger(),
        private readonly VulnFeedService $feed = new VulnFeedService(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Defensive STDOUT guard — carry-forward from P2.2 plan-bug #4.
        // as_schedule_single_action itself doesn't echo, but some upstream
        // plugins hook action_scheduler_pre_run_action and DO occasionally
        // echo on the synchronous scheduling path. Same pattern as
        // OverviewSyncAllController.
        ob_start();
        try {
            $userId = (int) $request->get_param('_authenticated_user_id');

            // Refresh the global feed ONCE before the fan-out (best-effort, no-ops without a key).
            $this->feed->refreshIfStale();

            $sites = $this->sites->findAllForUser($userId); // user-scoped — NOT findAllSchedulable()
            $ids   = array_map(static fn ($s) => $s->id, $sites);

            if (function_exists('as_schedule_single_action')) {
                foreach ($ids as $id) {
                    as_schedule_single_action(time(), SecurityScan::HOOK, [$id], 'defyn');
                }
            }

            if (count($ids) > 0) {
                $this->logger->log(
                    $userId,
                    null,                              // fleet-scoped — no single site
                    'security.scan_all_requested',
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
