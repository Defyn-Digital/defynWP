<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\BrandingService;
use Defyn\Dashboard\Services\ReportMailer;
use Defyn\Dashboard\Services\ReportsRepository;
use Defyn\Dashboard\Services\ReportStorage;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P5.3 Task 14 — POST /defyn/v1/sites/{id}/reports/{rid}/send.
 *
 * Emails a stored report PDF to a recipient. Ownership-404 runs first. A report
 * is sendable only when status is `ready` or `sent` and a stored file exists;
 * the recipient must pass is_email(). On wp_mail success → markSent + a
 * `report.sent` activity event → 200. On failure → 502, status stays `ready`.
 *
 * The ReportMailer is constructor-injected (default `new ReportMailer()`) so
 * tests can force send success/failure without dispatching real mail.
 */
final class SitesReportSendController
{
    public function __construct(private readonly ?ReportMailer $mailer = null)
    {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        $rid    = (int) $request->get_param('rid');
        $body   = $request->get_json_params() ?: [];

        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $reports = new ReportsRepository();
        $report = $reports->findByIdForSite($rid, $siteId);
        if ($report === null) {
            return ErrorResponse::create(404, 'reports.not_found', 'Report not found.');
        }
        if (!in_array($report->status, ['ready', 'sent'], true) || $report->fileName === null) {
            return ErrorResponse::create(400, 'reports.not_sendable', 'Report is not ready to send.');
        }

        $to = is_string($body['recipient_email'] ?? null) ? trim((string) $body['recipient_email']) : '';
        if (!is_email($to)) {
            return ErrorResponse::create(400, 'reports.invalid_recipient', 'A valid recipient email is required.');
        }
        $note = is_string($body['note'] ?? null) ? trim((string) $body['note']) : '';

        $branding = (new BrandingService())->get($userId);
        $agency   = (string) ($branding['agency_name'] ?? 'Defyn Digital');
        $host     = esc_html((string) parse_url($site->url, PHP_URL_HOST));
        $subject  = "{$agency} — Website Maintenance Report ({$report->rangeFrom} – {$report->rangeTo})";
        $intro    = $note !== '' ? '<p>' . esc_html($note) . '</p>' : '';
        $bodyHtml = $intro . '<p>Please find attached the website maintenance report for ' . $host
            . ', covering ' . esc_html($report->rangeFrom) . ' – ' . esc_html($report->rangeTo) . '.</p>'
            . '<p>— ' . esc_html($agency) . '</p>';

        $path = (new ReportStorage())->path($report->fileName);
        $ok = ($this->mailer ?? new ReportMailer())->send($to, $subject, $bodyHtml, $path);
        if (!$ok) {
            return ErrorResponse::create(502, 'reports.send_failed', 'The email could not be sent. Please try again.');
        }

        $reports->markSent($rid, $to, gmdate('Y-m-d H:i:s'));
        (new ActivityLogger())->log($userId, $siteId, 'report.sent', ['report_id' => $rid, 'recipient' => $to]);

        return new WP_REST_Response(['data' => ['report' => $reports->findByIdForSite($rid, $siteId)->toJson()], 'error' => null], 200);
    }
}
