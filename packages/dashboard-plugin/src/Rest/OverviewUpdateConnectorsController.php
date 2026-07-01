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
 * POST /defyn/v1/overview/update-connectors — fleet action. Enqueues a connector
 * self-update on every active site whose connector is below the latest release.
 */
final class OverviewUpdateConnectorsController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');

        $latest = (new ConnectorReleaseService())->latest();
        if ($latest === null) {
            return ErrorResponse::create(
                502,
                'connector.release_unavailable',
                'Could not resolve the latest connector release from GitHub.'
            );
        }

        $sites     = (new SitesRepository())->activeConnectorVersions();
        $logger    = new ActivityLogger();
        $scheduled = [];
        foreach ($sites as $row) {
            $current = $row['connector_version'];
            if ($current !== null && version_compare($current, $latest['version'], '>=')) {
                continue; // already current
            }
            \as_enqueue_async_action(
                UpdateSiteConnector::HOOK,
                [$row['id'], $latest['version'], $latest['package_url'], $latest['sha256']],
                'defyn'
            );
            $scheduled[] = $row['id'];
        }

        if ($scheduled !== []) {
            $logger->log($userId, null, 'overview.connector_update_requested', [
                'scheduled_count' => count($scheduled),
                'target_version'  => $latest['version'],
                'site_ids'        => $scheduled,
            ]);
            ImmediateRunner::kickOnShutdown();
        }

        return new WP_REST_Response([
            'scheduled_count' => count($scheduled),
            'target_version'  => $latest['version'],
        ], 202);
    }
}
