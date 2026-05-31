<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\ActivityLogRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /defyn/v1/activity — user-scoped paginated activity feed.
 *
 * Bearer-authenticated via RequireAuth::check (wired in RestRouter), which
 * stashes the user id on the request as `_authenticated_user_id`. This
 * controller is a thin shim: input coercion -> repo -> response envelope.
 * The SQL user-scoping (anti-leak guarantee) lives in
 * ActivityLogRepository::buildWhere — never here.
 *
 * Query params (all optional):
 *   - page         int (default 1, clamped >= 1)
 *   - per_page     int (default 50; repo clamps to [1, MAX_PER_PAGE])
 *   - event_type   string (exact match; null/empty = no filter)
 *   - site_id      int   (exact match; null/empty = no filter)
 *
 * Envelope: { events: [...], total: int, page: int, per_page: int }.
 * `per_page` echoes the CLAMPED value (min of request and MAX_PER_PAGE) so
 * clients can detect when they asked for more than the server allows.
 */
final class ActivityListController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId    = (int) $request->get_param('_authenticated_user_id');
        $page      = max(1, (int) ($request->get_param('page') ?? 1));
        $perPage   = (int) ($request->get_param('per_page') ?? 50);
        $eventType = $request->get_param('event_type');
        $siteId    = $request->get_param('site_id');

        $eventType = is_string($eventType) && $eventType !== '' ? $eventType : null;
        $siteId    = ($siteId !== null && $siteId !== '') ? (int) $siteId : null;

        $repo   = new ActivityLogRepository();
        $events = $repo->paginateForUser($userId, $siteId, $eventType, $page, $perPage);
        $total  = $repo->countForUser($userId, $siteId, $eventType);

        return new WP_REST_Response([
            'events'   => array_map(fn ($e) => $e->toJson(), $events),
            'total'    => $total,
            'page'     => $page,
            'per_page' => min(max(1, $perPage), ActivityLogRepository::MAX_PER_PAGE),
        ], 200);
    }
}
