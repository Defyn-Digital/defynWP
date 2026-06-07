<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\Rest\Responses\ErrorResponse;
use Defyn\Connector\SiteInfo\Collector;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles POST /defyn-connector/v1/core/refresh (spec § 3.2).
 *
 * Forces a fresh wp_version_check() poll then returns the freshly-collected
 * `core` sub-object (no surrounding `core` wrapper). Signature gate runs in
 * VerifySignatureMiddleware.
 */
final class CoreRefreshController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (!function_exists('wp_version_check')) {
            require_once ABSPATH . 'wp-includes/update.php';
        }

        if (!function_exists('wp_version_check')) {
            return ErrorResponse::create(
                502,
                'core.refresh_failed',
                'WP update subsystem unavailable on this site.'
            );
        }

        try {
            wp_version_check();
        } catch (\Throwable $e) {
            return ErrorResponse::create(
                502,
                'core.refresh_failed',
                'wp_version_check() failed: ' . $e->getMessage()
            );
        }

        $transient = get_site_transient('update_core');
        if ($transient === false || $transient === null) {
            return ErrorResponse::create(
                502,
                'core.refresh_failed',
                'wp_version_check() did not populate the update_core transient.'
            );
        }

        $full = (new Collector())->collect();
        $core = $full['core'] ?? [
            'update_available'       => false,
            'update_version'         => null,
            'is_minor_update'        => false,
            'is_auto_update_enabled' => true,
        ];
        $core['server_time'] = time();

        return new WP_REST_Response($core, 200);
    }
}
