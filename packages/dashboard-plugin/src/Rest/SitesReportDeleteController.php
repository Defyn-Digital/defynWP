<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\ReportsRepository;
use Defyn\Dashboard\Services\ReportStorage;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SitesReportDeleteController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        $rid    = (int) $request->get_param('rid');
        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }
        $repo = new ReportsRepository();
        $report = $repo->findByIdForSite($rid, $siteId);
        if ($report === null) {
            return ErrorResponse::create(404, 'reports.not_found', 'Report not found.');
        }
        if ($report->fileName !== null) {
            (new ReportStorage())->delete($report->fileName);
        }
        $repo->delete($rid);
        (new ActivityLogger())->log($userId, $siteId, 'report.deleted', ['report_id' => $rid]);
        return new WP_REST_Response(['data' => ['deleted' => true], 'error' => null], 200);
    }
}
