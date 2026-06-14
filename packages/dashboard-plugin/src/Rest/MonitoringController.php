<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\MonitoringService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P3.2 — GET /defyn/v1/monitoring. Read-only fleet uptime/latency view.
 * Mirrors OverviewController: direct payload, 30/min bucket.
 */
final class MonitoringController
{
    public function __construct(
        private readonly MonitoringService $service = new MonitoringService(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        return new WP_REST_Response($this->service->compose($userId), 200);
    }
}
