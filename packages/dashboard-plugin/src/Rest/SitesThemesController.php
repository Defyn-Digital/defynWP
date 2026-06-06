<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\ThemesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /defyn/v1/sites/{id}/themes — per-site theme inventory, user-scoped.
 *
 * Reads from wp_defyn_site_themes. The list is captured by the F7 background
 * defyn_sync_site job (extended in Task 19 to also schedule
 * defyn_refresh_site_themes), and refreshed on-demand via POST
 * /sites/{id}/themes/refresh (Task 17).
 *
 * Mirrors SitesPluginsListController's ownership gate: 404 sites.not_found
 * when the site doesn't exist OR isn't owned by the authenticated user.
 *
 * Envelope: { themes: [...], last_synced_at: ?string }.
 */
final class SitesThemesController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $repo         = new ThemesRepository();
        $rows         = $repo->findAllForSite($siteId);
        $lastSyncedAt = $repo->lastSyncedAtForSite($siteId);

        return new WP_REST_Response([
            'themes'         => array_map(static fn ($t) => $t->toJson(), $rows),
            'last_synced_at' => $lastSyncedAt,
        ], 200);
    }
}
