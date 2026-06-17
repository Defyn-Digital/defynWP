<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SiteAnalyticsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/** P6.2 — GET /sites/{id}/analytics — latest snapshot + connection status. */
final class SitesAnalyticsController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }
        $latest = (new SiteAnalyticsRepository())->latestForSite($siteId);
        return new WP_REST_Response([
            'data'  => ['latest' => $latest?->toJson(), 'ga4_property_id' => $site->ga4PropertyId],
            'error' => null,
        ], 200);
    }
}
