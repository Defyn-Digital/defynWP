<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\OverviewService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.5 — GET /defyn/v1/overview.
 *
 * Read-only aggregate view across all sites owned by the authenticated
 * user. Per spec § 3.2 — emits {pending_updates, sites_needing_attention,
 * recent_activity, generated_at}. Rate limited at 30/minute.
 *
 * Spec: docs/superpowers/specs/2026-06-07-p2-5-overview-dashboard-design.md § 3
 */
final class OverviewController
{
    public function __construct(
        private readonly OverviewService $service = new OverviewService(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        return new WP_REST_Response($this->service->compose($userId), 200);
    }
}
