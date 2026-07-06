<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\GenerateReport;
use Defyn\Dashboard\Jobs\ImmediateRunner;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Rest\Support\InvalidReportRange;
use Defyn\Dashboard\Rest\Support\ReportRange;
use Defyn\Dashboard\Services\ReportsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P5.3 Tasks 11+12 — client report queue endpoints.
 *
 *   POST /defyn/v1/sites/{id}/reports        → enqueue a generating report (202).
 *   GET  /defyn/v1/sites/{id}/reports?page=  → paginated list, newest first (200).
 *
 * Routes are registered separately (Task 17). Both methods ownership-gate the
 * site BEFORE any other work and return 404 sites.not_found when the caller does
 * not own it (mirrors SitesReportPdfController's secure ordering).
 */
final class SitesReportsController
{
    private const PER_PAGE = 20;

    public function handleCreate(WP_REST_Request $request): WP_REST_Response
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

        $repo = new ReportsRepository();
        $reportId = $repo->create(
            $siteId,
            'Website Maintenance Report',
            $range['from_date'],
            $range['to_date'],
            gmdate('Y-m-d H:i:s')
        );

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(GenerateReport::HOOK, [$reportId], 'defyn');
            // Kick the queue on shutdown so the render runs in ~1s (matches the
            // interactive update controllers) rather than waiting for cron —
            // this is what makes the on-demand "Download PDF" feel instant.
            ImmediateRunner::kickOnShutdown();
        }

        $report = $repo->findByIdForSite($reportId, $siteId);

        return new WP_REST_Response([
            'data'  => ['report' => $report->toJson()],
            'error' => null,
        ], 202);
    }

    public function handleList(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $page = max(1, (int) $request->get_param('page'));
        $repo = new ReportsRepository();

        $reports = array_map(
            static fn ($report) => $report->toJson(),
            $repo->findForSite($siteId, self::PER_PAGE, ($page - 1) * self::PER_PAGE)
        );

        return new WP_REST_Response([
            'data'  => [
                'reports'  => $reports,
                'total'    => $repo->countForSite($siteId),
                'page'     => $page,
                'per_page' => self::PER_PAGE,
            ],
            'error' => null,
        ], 200);
    }
}
