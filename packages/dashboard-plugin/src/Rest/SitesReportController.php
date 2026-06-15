<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
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
    private const MAX_SPAN_DAYS = 366;

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $fromParam = $request->get_param('from');
        $toParam   = $request->get_param('to');

        if ($fromParam === null && $toParam === null) {
            $toDate   = gmdate('Y-m-d');
            $fromDate = gmdate('Y-m-d', time() - 30 * 86400);
        } else {
            $fromDate = $this->parseDate(is_string($fromParam) ? $fromParam : '');
            $toDate   = $this->parseDate(is_string($toParam) ? $toParam : '');
            if ($fromDate === null || $toDate === null) {
                return ErrorResponse::create(400, 'report.invalid_range', 'from/to must be valid YYYY-MM-DD dates.');
            }
        }

        if (strcmp($fromDate, $toDate) > 0) {
            return ErrorResponse::create(400, 'report.invalid_range', 'from must be on or before to.');
        }
        $spanDays = (strtotime($toDate . ' UTC') - strtotime($fromDate . ' UTC')) / 86400;
        if ($spanDays > self::MAX_SPAN_DAYS) {
            return ErrorResponse::create(400, 'report.range_too_large', 'Date range exceeds the maximum of 366 days.');
        }

        $report = (new ReportService())->compose($siteId, $userId, $fromDate . ' 00:00:00', $toDate . ' 23:59:59');
        return new WP_REST_Response(['data' => $report, 'error' => null], 200);
    }

    /** Strict YYYY-MM-DD; returns the normalized date or null. */
    private function parseDate(string $value): ?string
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($d === false || $d->format('Y-m-d') !== $value) {
            return null;
        }
        return $value;
    }
}
