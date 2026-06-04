<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\Rest\Responses\ErrorResponse;
use Defyn\Connector\SiteInfo\PluginListCollector;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles POST /defyn-connector/v1/plugins/refresh (spec § 3.2).
 *
 * Forces a fresh wp.org update poll via wp_update_plugins() then returns
 * the cached `/plugins` payload. The signature gate runs in
 * VerifySignatureMiddleware::check (permission_callback in RestRouter).
 */
final class PluginsRefreshController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (!function_exists('wp_update_plugins')) {
            require_once ABSPATH . 'wp-includes/update.php';
        }

        if (!function_exists('wp_update_plugins')) {
            return ErrorResponse::create(
                502,
                'connector.refresh_failed',
                'WP update subsystem unavailable on this site.'
            );
        }

        try {
            wp_update_plugins();
        } catch (\Throwable $e) {
            return ErrorResponse::create(
                502,
                'connector.refresh_failed',
                'wp_update_plugins() failed: ' . $e->getMessage()
            );
        }

        $data                = (new PluginListCollector())->collect();
        $data['server_time'] = time();
        return new WP_REST_Response($data, 200);
    }
}
