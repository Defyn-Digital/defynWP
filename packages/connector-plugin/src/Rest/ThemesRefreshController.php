<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\Rest\Responses\ErrorResponse;
use Defyn\Connector\SiteInfo\ThemeListCollector;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles POST /defyn-connector/v1/themes/refresh (spec § 3.2).
 *
 * Forces a fresh wp.org update poll via wp_update_themes() then returns
 * the freshly-collected /themes payload. Signature gate runs in
 * VerifySignatureMiddleware::check (permission_callback in RestRouter).
 */
final class ThemesRefreshController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (!function_exists('wp_update_themes')) {
            require_once ABSPATH . 'wp-includes/update.php';
        }

        if (!function_exists('wp_update_themes')) {
            return ErrorResponse::create(
                502,
                'themes.refresh_failed',
                'WP update subsystem unavailable on this site.'
            );
        }

        try {
            wp_update_themes();
        } catch (\Throwable $e) {
            return ErrorResponse::create(
                502,
                'themes.refresh_failed',
                'wp_update_themes() failed: ' . $e->getMessage()
            );
        }

        // wp_update_themes() can silently fail (the transient isn't populated
        // because pre_set_site_transient_update_themes returned false). When
        // the refresh failed for any reason, surface 502 so the dashboard
        // doesn't log a misleading success.
        $transient = get_site_transient('update_themes');
        if ($transient === false || $transient === null) {
            return ErrorResponse::create(
                502,
                'themes.refresh_failed',
                'wp_update_themes() did not populate the update_themes transient.'
            );
        }

        $data                = (new ThemeListCollector())->collect();
        $data['server_time'] = time();
        return new WP_REST_Response($data, 200);
    }
}
