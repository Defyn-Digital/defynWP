<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\ImmediateRunner;
use Defyn\Dashboard\Jobs\UpdateSiteConnector;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\ConnectorReleaseService;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /defyn/v1/sites/{id}/connector/update — upgrade one site's connector to
 * the latest GitHub release. Resolves the manifest, logs the request, enqueues
 * UpdateSiteConnector async (started immediately by ImmediateRunner), 202.
 */
final class SitesConnectorUpdateController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $latest = (new ConnectorReleaseService())->latest();
        if ($latest === null) {
            return ErrorResponse::create(
                502,
                'connector.release_unavailable',
                'Could not resolve the latest connector release from GitHub.'
            );
        }

        if ($site->connectorVersion !== null && version_compare($site->connectorVersion, $latest['version'], '>=')) {
            return ErrorResponse::create(
                409,
                'connector.already_current',
                sprintf('Connector is already at %s.', $site->connectorVersion)
            );
        }

        (new ActivityLogger())->log($userId, $siteId, 'connector_update.requested', [
            'from_version'   => $site->connectorVersion,
            'target_version' => $latest['version'],
        ]);

        \as_enqueue_async_action(
            UpdateSiteConnector::HOOK,
            [$siteId, $latest['version'], $latest['package_url'], $latest['sha256']],
            'defyn'
        );
        ImmediateRunner::kickOnShutdown();

        return new WP_REST_Response([
            'scheduled'      => true,
            'site_id'        => $siteId,
            'target_version' => $latest['version'],
        ], 202);
    }
}
