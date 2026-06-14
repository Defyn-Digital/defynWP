<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\IncidentsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P3.1 — GET /defyn/v1/sites/{id}/incidents
 *
 * Returns a paginated list of incidents for the given site, newest-first.
 * Mirrors SitesThemesController for auth + ownership gate; mirrors
 * JobsListController for limit/offset pagination.
 *
 * Envelope: { data: { incidents: [...] }, error: null }
 * Rate limit: 30/MINUTE via RateLimit::sitesIncidents.
 */
final class SitesIncidentsController
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 100;

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $limit  = (int) ($request->get_param('limit') ?: self::DEFAULT_LIMIT);
        $limit  = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, (int) ($request->get_param('offset') ?: 0));

        $incidents = (new IncidentsRepository())->findForSite($siteId, $limit, $offset);

        return new WP_REST_Response([
            'data'  => [
                'incidents' => array_map(static fn ($i) => $i->toJson(), $incidents),
            ],
            'error' => null,
        ], 200);
    }
}
