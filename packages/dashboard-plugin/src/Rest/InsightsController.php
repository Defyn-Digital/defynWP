<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\InsightsService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P6.3 — GET /defyn/v1/insights. Read-only combined fleet Performance + Analytics
 * rollup. Mirrors SecurityController: direct payload, 30/min bucket, ownership
 * scoped via InsightsService::compose($userId).
 */
final class InsightsController
{
    public function __construct(
        private readonly InsightsService $service = new InsightsService(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        return new WP_REST_Response($this->service->compose($userId), 200);
    }
}
