<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\BrokenLinksRepository;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P7.1 — GET /defyn/v1/sites/{id}/broken-links
 * Returns the stored broken-link findings for the given site (last scan timestamp,
 * aggregate counts, and the full link list). Ownership-gated: 404 sites.not_found
 * when the site is not owned by the caller.
 * Envelope: { data: { last_link_scan_at: string|null, counts: {...}, links: [...] }, error: null }.
 */
final class SitesBrokenLinksController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);

        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $repo = new BrokenLinksRepository();

        return new WP_REST_Response([
            'data' => [
                'last_link_scan_at' => $site->lastLinkScanAt,
                'counts'            => $repo->countsForSite($siteId),
                'links'             => $repo->findForSite($siteId),
            ],
            'error' => null,
        ], 200);
    }
}
