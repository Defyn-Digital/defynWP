<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ReportsRepository;
use Defyn\Dashboard\Services\ReportStorage;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P5.3 — GET /defyn/v1/sites/{id}/reports/{rid}/download → stream a stored report PDF.
 * Error branches return JSON (testable). The success path streams binary via emit()
 * — a seam the tests override to capture the bytes; the real emit() header()+echo()+exit()s,
 * which would kill PHPUnit.
 *
 * NOT `final` — the success test subclasses it to override emit() (mirrors SitesReportPdfController).
 */
class SitesReportDownloadController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        $rid    = (int) $request->get_param('rid');

        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $report = (new ReportsRepository())->findByIdForSite($rid, $siteId);
        if ($report === null) {
            return ErrorResponse::create(404, 'reports.not_found', 'Report not found.');
        }

        if (!in_array($report->status, ['ready', 'sent'], true) || $report->fileName === null) {
            return ErrorResponse::create(409, 'reports.not_ready', 'Report is not ready to download.');
        }

        $bytes = (new ReportStorage())->read($report->fileName);
        if ($bytes === null) {
            return ErrorResponse::create(409, 'reports.not_ready', 'Report file is missing.');
        }

        $host = preg_replace('/[^a-z0-9.-]+/i', '-', (string) parse_url($site->url, PHP_URL_HOST)) ?: 'site';
        $this->emit($bytes, "Website-Maintenance-Report-{$host}-{$report->rangeFrom}-to-{$report->rangeTo}.pdf");
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
