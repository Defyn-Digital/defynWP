<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles GET /defyn-connector/v1/heartbeat (spec § 5.1).
 *
 * Lightweight liveness probe — cheaper than /status because it skips
 * SiteInfo collection. The signature gate runs in
 * VerifySignatureMiddleware::check (registered as permission_callback
 * in RestRouter), so by the time this handler runs the request is
 * already authentic.
 */
final class HeartbeatController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => true, 'server_time' => time()], 200);
    }
}
