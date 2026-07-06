<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Rest\Support\InvalidReportRange;
use Defyn\Dashboard\Rest\Support\ReportRange;
use Defyn\Dashboard\Services\BrandingService;
use Defyn\Dashboard\Services\ReportPdfService;
use Defyn\Dashboard\Services\ReportService;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P5.2 — GET /defyn/v1/sites/{id}/report.pdf?from&to → branded PDF download.
 * Error branches return JSON (testable via rest_do_request). The success path
 * emits binary via emit() — a seam the tests override to capture the bytes; the
 * real emit() header()+echo()+exit()s, which would kill PHPUnit.
 *
 * NOT `final`: the protected emit() seam is overridden by a test subclass that
 * captures the bytes instead of streaming+exiting (see SitesReportPdfTest).
 */
class SitesReportPdfController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        // dompdf is memory-hungry. On some hosts (e.g. Kinsta) the REST/web-tier
        // request context has tighter memory/time limits than the CLI/cron context
        // the scheduled GenerateReport job runs in — which made this on-demand
        // endpoint 503 (worker OOM/timeout) while the async report rendered fine.
        // Raise the ceilings for this single request so the sync render can finish.
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        @ini_set('memory_limit', '512M');
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        // Ownership check MUST run before date validation (secure ordering).
        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) {
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

        try {
            $report   = (new ReportService())->compose($siteId, $userId, $range['from'], $range['to']);
            $branding = (new BrandingService())->getForSite($userId, $siteId);
            $pdf      = (new ReportPdfService())->render($report, $branding);
        } catch (\Throwable $e) {
            // Fail with a clean JSON error instead of a hard 500/503 white-screen,
            // so the SPA can surface a message and the cause is diagnosable.
            return ErrorResponse::create(500, 'reports.pdf_failed', 'Could not render the report PDF: ' . $e->getMessage());
        }

        $host     = preg_replace('/[^a-z0-9.-]+/i', '-', (string) wp_parse_url($site->url, PHP_URL_HOST)) ?: 'site';
        $filename = "maintenance-report-{$host}-{$range['from_date']}-to-{$range['to_date']}.pdf";

        $this->emit($pdf, $filename);   // production: exits; test override: returns
        return new WP_REST_Response(null, 200);
    }

    /** Production: stream the PDF as a download and stop. Overridden in tests. */
    protected function emit(string $pdf, string $filename): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdf));
        }
        echo $pdf;
        exit;
    }
}
