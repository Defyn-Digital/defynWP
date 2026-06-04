<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\SiteInfo\PluginListCollector;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles GET /defyn-connector/v1/plugins (spec § 3.1).
 *
 * Thin shim: the signature gate runs in VerifySignatureMiddleware::check
 * (registered as permission_callback in RestRouter), so by the time this
 * handler runs the request is authentic and we just return the inventory.
 */
final class PluginsListController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $data                = (new PluginListCollector())->collect();
        $data['server_time'] = time();
        return new WP_REST_Response($data, 200);
    }
}
