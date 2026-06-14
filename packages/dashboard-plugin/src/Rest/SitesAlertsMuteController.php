<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P3.3 — POST /defyn/v1/sites/{id}/alerts/mute.
 *
 * Toggles the per-site alerts_muted flag. When muted, alert notifications
 * (Slack etc.) are suppressed for the site. The SPA's site card reflects
 * the muted state immediately after the toggle.
 *
 * Exact structural mirror of SitesCoreAllowMajorController (P2.4.1).
 */
final class SitesAlertsMuteController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $body = $request->get_json_params() ?: [];
        if (!array_key_exists('muted', $body) || !is_bool($body['muted'])) {
            return ErrorResponse::create(400, 'alerts.invalid_payload', 'Request body must include a boolean "muted" field.');
        }
        $muted = (bool) $body['muted'];

        (new SitesRepository())->setAlertsMuted($siteId, $muted);
        (new ActivityLogger())->log($userId, $siteId, $muted ? 'site.alerts_muted' : 'site.alerts_unmuted', []);

        return new WP_REST_Response(['site_id' => $siteId, 'alerts_muted' => $muted], 200);
    }
}
