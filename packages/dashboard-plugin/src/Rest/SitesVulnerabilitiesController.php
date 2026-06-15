<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P4.1 — GET /defyn/v1/sites/{id}/vulnerabilities
 *
 * Returns the latest security-scan snapshot for the given site.
 * `scanned_at` comes from Site->lastSecurityScanAt (not the findings rows).
 * Ownership-gated: 404 when the site is not owned by the authenticated user.
 *
 * Envelope: { data: { scanned_at: string|null, vulnerabilities: [...] }, error: null }
 * Rate limit: 30/MINUTE via RateLimit::siteVulnerabilities.
 */
final class SitesVulnerabilitiesController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $findings = (new SiteVulnerabilitiesRepository())->findForSite($siteId);

        return new WP_REST_Response([
            'data' => [
                'scanned_at'      => $site->lastSecurityScanAt,
                'vulnerabilities' => array_map(static fn ($v) => $v->toJson(), $findings),
            ],
            'error' => null,
        ], 200);
    }
}
