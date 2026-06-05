<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /defyn/v1/sites/{id}/plugins — per-site plugin inventory, user-scoped.
 *
 * Reads from wp_defyn_site_plugins (spec § 6.3). The list is captured by F7's
 * background defyn_sync_site job (extended in Task 9) every 30 min, and
 * refreshed on-demand via POST /sites/{id}/plugins/refresh (Task 11).
 *
 * Mirrors SitesActivityController's ownership gate: 404 `sites.not_found` when
 * the site doesn't exist OR isn't owned by the authenticated user (same
 * anti-enumeration pattern as SitesShowController / SitesDeleteController).
 *
 * Envelope: { plugins: [...], total: int, last_synced_at: ?string }.
 */
final class SitesPluginsListController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $repo         = new SitePluginsRepository();
        $rows         = $repo->findAllForSite($siteId);
        $lastSyncedAt = $repo->lastSyncedAtForSite($siteId);

        return new WP_REST_Response([
            'plugins'        => array_map(static fn ($p) => $p->toJson(), $rows),
            'total'          => count($rows),
            'last_synced_at' => $lastSyncedAt,
        ], 200);
    }
}
