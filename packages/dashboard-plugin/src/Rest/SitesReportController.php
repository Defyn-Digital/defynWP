<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Rest\Support\InvalidReportRange;
use Defyn\Dashboard\Rest\Support\ReportRange;
use Defyn\Dashboard\Services\ReportService;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P5.1 — GET /defyn/v1/sites/{id}/report?from=YYYY-MM-DD&to=YYYY-MM-DD
 * Read-only maintenance report over a date range. Defaults to the trailing 30 days.
 * Envelope: { data: {…}, error: null }.
 */
final class SitesReportController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        // Ownership check MUST run before date validation (secure ordering).
        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        try {
            $range = ReportRange::resolve(
                is_string($request->get_param('from')) ? $request->get_param('from') : null,
                is_string($request->get_param('to')) ? $request->get_param('to') : null,
            );
        } catch (InvalidReportRange $e) {
            return ErrorResponse::create(400, $e->code, $e->getMessage());
        }

        $report = (new ReportService())->compose($siteId, $userId, $range['from'], $range['to']);
        return new WP_REST_Response(['data' => $report, 'error' => null], 200);
    }
}
