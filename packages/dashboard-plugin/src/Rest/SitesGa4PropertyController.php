<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/** P6.2 — POST /sites/{id}/ga4-property — set/clear the numeric GA4 property ID. */
final class SitesGa4PropertyController
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
        $raw = is_string($body['ga4_property_id'] ?? null) ? trim((string) $body['ga4_property_id']) : '';
        if ($raw !== '' && preg_match('/^\d{1,32}$/', $raw) !== 1) {
            return ErrorResponse::create(400, 'analytics.invalid_property_id', 'A numeric GA4 property ID (not the G- measurement ID) or empty value is required.');
        }
        $sites->setGa4PropertyId($siteId, $raw === '' ? null : $raw);
        return new WP_REST_Response(['data' => ['ga4_property_id' => $raw === '' ? null : $raw], 'error' => null], 200);
    }
}
