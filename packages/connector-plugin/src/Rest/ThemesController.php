<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\SiteInfo\ThemeListCollector;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles GET /defyn-connector/v1/themes (spec § 3.1).
 *
 * Thin shim: signature gate runs in VerifySignatureMiddleware::check
 * (registered as permission_callback in RestRouter), so by the time this
 * handler runs the request is authentic and we just return the inventory.
 */
final class ThemesController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $data                = (new ThemeListCollector())->collect();
        $data['server_time'] = time();
        return new WP_REST_Response($data, 200);
    }
}
