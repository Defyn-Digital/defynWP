<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\DisconnectService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * DELETE /defyn/v1/sites/{id} — SPA-triggered soft disconnect.
 *
 * Thin shim: delegates to DisconnectService which signs POST /disconnect to
 * the connector AND deletes the dashboard row. Failure-tolerant by design —
 * a broken/offline connector must not strand the operator, so the row is
 * deleted regardless of connector outcome (handled inside the service).
 *
 * User-scoped: returns 404 sites.not_found for both "not found" and
 * "not owned" so attackers cannot enumerate other users' site IDs.
 */
final class SitesDeleteController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        $deleted = (new DisconnectService())->disconnect($siteId, $userId);
        if (!$deleted) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        return new WP_REST_Response(null, 204);
    }
}
