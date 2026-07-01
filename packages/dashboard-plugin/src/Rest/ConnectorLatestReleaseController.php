<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ConnectorReleaseService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /defyn/v1/connector/latest-release.
 * Returns the latest connector release resolved from GitHub {version, package_url, sha256}.
 */
final class ConnectorLatestReleaseController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $forceRefresh = (bool) $request->get_param('refresh');
        $latest = (new ConnectorReleaseService())->latest($forceRefresh);
        if ($latest === null) {
            return ErrorResponse::create(
                502,
                'connector.release_unavailable',
                'Could not resolve the latest connector release from GitHub.'
            );
        }
        return new WP_REST_Response($latest, 200);
    }
}
