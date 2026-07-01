<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\BrandingService;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /defyn/v1/sites/{id}/report-branding — set this site's white-label report
 * branding overrides. Body may include any of agency_name, accent_color,
 * logo_url. An empty string clears that override (falls back to the team-wide
 * default). Only provided keys are changed.
 */
final class SitesReportBrandingController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        $body   = $request->get_json_params() ?: [];

        $sites = new SitesRepository();
        if ($sites->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $partial = [];
        if (array_key_exists('agency_name', $body)) {
            $partial['report_agency_name'] = trim((string) $body['agency_name']);
        }
        if (array_key_exists('logo_url', $body)) {
            $logo = trim((string) $body['logo_url']);
            if ($logo !== '' && !preg_match('#^https?://#i', $logo)) {
                return ErrorResponse::create(400, 'sites.invalid_logo_url', 'Logo URL must start with http(s):// or be empty.');
            }
            $partial['report_logo_url'] = $logo;
        }
        if (array_key_exists('accent_color', $body)) {
            $accent = trim((string) $body['accent_color']);
            if ($accent !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
                return ErrorResponse::create(400, 'sites.invalid_accent_color', 'Accent must be a #RRGGBB hex colour or empty.');
            }
            $partial['report_accent_color'] = $accent;
        }

        if ($partial === []) {
            return ErrorResponse::create(400, 'sites.no_branding_fields', 'Provide agency_name, logo_url and/or accent_color.');
        }

        $sites->setReportBranding($siteId, $partial);

        // Return both the raw overrides and the effective (merged) branding.
        $effective = (new BrandingService())->getForSite($userId, $siteId);
        return new WP_REST_Response(['data' => ['effective' => $effective], 'error' => null], 200);
    }
}
