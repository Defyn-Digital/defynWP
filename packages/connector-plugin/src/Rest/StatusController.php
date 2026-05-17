<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\SiteInfo\Collector;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles GET /defyn-connector/v1/status (spec § 5.1).
 *
 * Thin shim: the signature gate runs in VerifySignatureMiddleware::check
 * (registered as permission_callback in RestRouter), so by the time this
 * handler runs the request is authentic and we just return the snapshot.
 */
final class StatusController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response((new Collector())->collect(), 200);
    }
}
