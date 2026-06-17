<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Schema\ReportsTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\BrandingService;
use Defyn\Dashboard\Services\ReportPdfService;
use Defyn\Dashboard\Services\ReportSendService;
use Defyn\Dashboard\Services\ReportService;
use Defyn\Dashboard\Services\ReportsRepository;
use Defyn\Dashboard\Services\ReportStorage;
use Defyn\Dashboard\Services\SitesRepository;

/**
 * P5.3 — async report-generation Action Scheduler job.
 *
 * Loads a `generating` report row, resolves its owning site (system context — no
 * user scope), composes the report, renders the branded PDF, stores it, and marks
 * the row `ready`. Guardrail #3: any Throwable is caught → markFailed; the job
 * NEVER rethrows, so a later monthly fan-out can't be broken by one bad site.
 */
final class GenerateReport
{
    public const HOOK = 'defyn_generate_report';

    public function __construct(
        private readonly ?ReportsRepository $reports = null,
        private readonly ?ReportStorage $storage = null,
        private readonly ?ReportPdfService $pdf = null,
        private readonly ?ReportSendService $sender = null,
    ) {
    }

    public function handle(int $reportId): void
    {
        $reports = $this->reports ?? new ReportsRepository();

        $row = $this->loadAnyRow($reportId);
        if ($row === null) {
            return; // deleted before the job ran — no-op
        }
        $siteId = (int) $row['site_id'];
        $site   = (new SitesRepository())->findById($siteId);
        if ($site === null) {
            $reports->markFailed($reportId, 'Owning site no longer exists.');
            return;
        }
        $ownerId = $site->userId;

        try {
            $payload  = (new ReportService())->compose(
                $siteId,
                $ownerId,
                $row['range_from'] . ' 00:00:00',
                $row['range_to'] . ' 23:59:59',
            );
            $branding = (new BrandingService())->get($ownerId);
            $bytes    = ($this->pdf ?? new ReportPdfService())->render($payload, $branding);
            $stored   = ($this->storage ?? new ReportStorage())->store($reportId, $bytes);
            $reports->markReady($reportId, $stored['file_name'], $stored['size'], gmdate('Y-m-d H:i:s'));
            (new ActivityLogger())->log($ownerId, $siteId, 'report.generated', [
                'report_id'  => $reportId,
                'range_from' => $row['range_from'],
                'range_to'   => $row['range_to'],
            ]);
        } catch (\Throwable $e) {
            $reports->markFailed($reportId, $e->getMessage());
            (new ActivityLogger())->log($ownerId, $siteId, 'report.generation_failed', [
                'report_id' => $reportId,
                'error'     => $e->getMessage(),
            ]);
        }

        // P5.4 — best-effort auto-send. Generation is already committed above; a
        // send failure (or throw) must never undo it — the report stays `ready`
        // for manual retry. Only fires when the site is opted-in AND has a valid
        // client email AND the report actually reached `ready`.
        $finalReport = $reports->findByIdForSite($reportId, $siteId);
        if ($finalReport !== null
            && $finalReport->status === 'ready'
            && $site->autoSendReports
            && is_email((string) $site->clientEmail)) {
            try {
                ($this->sender ?? new ReportSendService())->send($finalReport, $site, (string) $site->clientEmail, null, 'auto');
            } catch (\Throwable $e) {
                // swallow — the report stays `ready`; operator can send manually.
            }
        }
    }

    /**
     * Raw row read — we need site_id before any ownership scoping because the
     * monthly system job runs with no user context.
     *
     * @return array<string,mixed>|null
     */
    private function loadAnyRow(int $reportId): ?array
    {
        global $wpdb;
        $t = ReportsTable::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL — table name is a constant.
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $reportId), ARRAY_A);
        return $row ?: null;
    }
}
