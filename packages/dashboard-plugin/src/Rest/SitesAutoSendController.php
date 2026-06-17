<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/** P5.4 — POST /sites/{id}/auto-send — per-site auto-send opt-in toggle. */
final class SitesAutoSendController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        $body   = $request->get_json_params() ?: [];
        $sites  = new SitesRepository();
        if ($sites->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }
        $on = ($body['auto_send'] ?? null) === true; // strict boolean opt-in
        $sites->setAutoSendReports($siteId, $on);
        return new WP_REST_Response(['data' => ['auto_send_reports' => $on], 'error' => null], 200);
    }
}
