<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\SecurityService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P4.2 — GET /defyn/v1/security. Read-only fleet vulnerability view.
 * Mirrors MonitoringController: direct payload, 30/min bucket.
 */
final class SecurityController
{
    public function __construct(
        private readonly SecurityService $service = new SecurityService(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        return new WP_REST_Response($this->service->compose($userId), 200);
    }
}
