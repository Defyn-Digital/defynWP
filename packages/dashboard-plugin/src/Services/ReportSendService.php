<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\Report;
use Defyn\Dashboard\Models\Site;

/**
 * P5.4 — the single email-build + send + markSent + log path for a stored
 * report PDF. Used by BOTH the manual send controller (method='manual',
 * event report.sent) and the auto-send chain in GenerateReport
 * (method='auto', event report.auto_sent), so the two can't drift. The email
 * body is byte-identical to the P5.3 manual flow it was extracted from.
 *
 * Returns true on wp_mail success (report marked `sent`); false leaves the
 * report's status unchanged so the caller can surface a retry path.
 */
final class ReportSendService
{
    public function __construct(private readonly ?ReportMailer $mailer = null)
    {
    }

    /** @param 'manual'|'auto' $method */
    public function send(Report $report, Site $site, string $to, ?string $note, string $method): bool
    {
        $branding  = (new BrandingService())->getForSite($site->userId, $site->id);
        $agency    = (string) ($branding['agency_name'] ?? '');
        $host      = (string) wp_parse_url($site->url, PHP_URL_HOST);
        // Site-led: the report is about the client's site, so lead with its
        // label (falling back to the host when no label is set). The agency
        // only appears when the operator has explicitly configured one.
        $siteLabel    = $site->label !== '' ? $site->label : $host;
        $agencySuffix = $agency !== '' ? ' — ' . $agency : '';
        $subject   = $siteLabel . ' — Website Maintenance Report (' . $report->rangeFrom . ' – ' . $report->rangeTo . ')' . $agencySuffix;
        $intro     = $note !== null && $note !== '' ? '<p>' . esc_html($note) . '</p>' : '';
        $signOff   = $agency !== '' ? '<p>— ' . esc_html($agency) . '</p>' : '';
        $bodyHtml  = $intro . '<p>Please find attached the website maintenance report for ' . esc_html($siteLabel)
            . ', covering ' . esc_html($report->rangeFrom) . ' – ' . esc_html($report->rangeTo) . '.</p>'
            . $signOff;

        $path = (new ReportStorage())->path((string) $report->fileName);
        $ok   = ($this->mailer ?? new ReportMailer())->send($to, $subject, $bodyHtml, $path);
        if (!$ok) {
            return false;
        }

        (new ReportsRepository())->markSent($report->id, $to, gmdate('Y-m-d H:i:s'), $method);
        (new ActivityLogger())->log(
            $site->userId,
            $site->id,
            $method === 'auto' ? 'report.auto_sent' : 'report.sent',
            ['report_id' => $report->id, 'recipient' => $to]
        );
        return true;
    }
}
