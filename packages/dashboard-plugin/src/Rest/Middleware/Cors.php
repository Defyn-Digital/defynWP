<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest\Middleware;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Adds CORS headers to every defyn/v1/* response.
 *
 * Wired via the `rest_pre_serve_request` filter in RestRouter::register().
 * Returns the $served bool unchanged — we only add headers to the response.
 */
final class Cors
{
    /**
     * @param bool                  $served
     * @param WP_REST_Response      $response
     * @param WP_REST_Request|null  $request
     * @param WP_REST_Server        $server
     */
    public static function apply($served, $response, $request, $server): bool
    {
        // Only apply to our namespace.
        if ($request instanceof WP_REST_Request) {
            $route = $request->get_route();
            if (strpos($route, '/defyn/v1') !== 0) {
                return (bool) $served;
            }
        }

        $response->header('Access-Control-Allow-Origin', DEFYN_SPA_ORIGIN);
        $response->header('Access-Control-Allow-Credentials', 'true');
        $response->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Authorization, Content-Type');
        $response->header('Vary', 'Origin');

        return (bool) $served;
    }
}
