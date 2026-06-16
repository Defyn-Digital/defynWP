<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\ReportsRepository;
use Defyn\Dashboard\Services\SitesRepository;

/**
 * P5.3 — recurring fan-out master: every ~30 days, for each schedulable site
 * compute the PREVIOUS calendar month and, unless a report already exists for
 * that site+month (dedup), create a `generating` row + enqueue the per-site
 * GenerateReport leaf job.
 *
 * Mirrors SecurityScanAll's shape — the differences are the per-site dedup +
 * row creation and the enqueued HOOK.
 */
final class GenerateMonthlyReportsAll
{
    public const HOOK = 'defyn_generate_monthly_reports_all';

    public function __construct(
        private readonly ?SitesRepository $sites = null,
        private readonly ?ReportsRepository $reports = null,
    ) {
    }

    /** @return array{0:string,1:string} [first-day, last-day] of the calendar month before $today (UTC). */
    public static function previousMonthRange(string $today): array
    {
        $thisFirst = gmdate('Y-m-01', strtotime($today . ' UTC'));
        $prevLast  = gmdate('Y-m-d', strtotime($thisFirst . ' UTC') - 86400);
        $prevFirst = gmdate('Y-m-01', strtotime($prevLast . ' UTC'));
        return [$prevFirst, $prevLast];
    }

    public function handle(): void
    {
        $sites   = $this->sites ?? new SitesRepository();
        $reports = $this->reports ?? new ReportsRepository();
        [$from, $to] = self::previousMonthRange(gmdate('Y-m-d'));
        $now = gmdate('Y-m-d H:i:s');

        foreach ($sites->findAllSchedulable() as $siteId) {
            if ($reports->existsForSiteAndMonth($siteId, $from, $to)) {
                continue;
            }
            $reportId = $reports->create($siteId, 'Website Maintenance Report', $from, $to, $now);
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(GenerateReport::HOOK, [$reportId], 'defyn');
            }
        }
    }
}
