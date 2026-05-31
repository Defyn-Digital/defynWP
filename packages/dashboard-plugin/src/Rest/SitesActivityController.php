<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogRepository;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /defyn/v1/sites/{id}/activity — per-site activity feed, user-scoped.
 *
 * Mirrors ActivityListController (Task 4) but adds an ownership gate first:
 * if the site doesn't exist OR isn't owned by the authenticated user, returns
 * 404 `sites.not_found` (anti-enumeration — same pattern as SitesShowController
 * and SitesDeleteController). The repo's user-scoping subquery still applies
 * as defense in depth.
 *
 * Envelope: { events: [...], total: int, page: int, per_page: int }.
 */
final class SitesActivityController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $page    = max(1, (int) ($request->get_param('page') ?? 1));
        $perPage = (int) ($request->get_param('per_page') ?? 50);

        $repo   = new ActivityLogRepository();
        $events = $repo->paginateForUser($userId, $siteId, null, $page, $perPage);
        $total  = $repo->countForUser($userId, $siteId, null);

        return new WP_REST_Response([
            'events'   => array_map(fn ($e) => $e->toJson(), $events),
            'total'    => $total,
            'page'     => $page,
            'per_page' => min(max(1, $perPage), ActivityLogRepository::MAX_PER_PAGE),
        ], 200);
    }
}
