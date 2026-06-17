<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/**
 * P6.2 — per-site GA4 fetch orchestrator. Resolves a site, fetches the current
 * + previous calendar month via Ga4Client, upserts each month's snapshot, then
 * emits ONE `site.analytics_synced` activity event. Best-effort (guardrail #2):
 * a missing site / empty property ID is a skip; a null fetch for a month simply
 * stores nothing for that month; NEVER throws into the weekly fan-out. Mirrors
 * PerformanceScanService.
 */
final class AnalyticsScanService
{
    public function __construct(
        private readonly ?SiteAnalyticsRepository $repo = null,
        private readonly ?SitesRepository $sites = null,
    ) {
    }

    public function scan(int $siteId, ?Ga4Client $client = null): void
    {
        $site = ($this->sites ?? new SitesRepository())->findById($siteId);
        if ($site === null) {
            return;
        }
        $propertyId = $site->ga4PropertyId;
        if ($propertyId === null || $propertyId === '') {
            return; // not connected — skip, no row, no event
        }

        $client = $client ?? new Ga4Client();
        $repo   = $this->repo ?? new SiteAnalyticsRepository();
        $now    = gmdate('Y-m-d H:i:s');
        $synced = 0;

        foreach (self::monthBounds(time()) as [$start, $end]) {
            $data = $client->fetchReport($propertyId, $start, $end);
            if ($data === null) {
                continue;
            }
            $repo->upsertForSiteAndPeriod($siteId, $start, $end, $data, $now, $now);
            $synced++;
        }

        if ($synced > 0) {
            (new ActivityLogger())->log($site->userId, $siteId, 'site.analytics_synced', ['months_synced' => $synced]);
        }
    }

    /**
     * Current and previous calendar month, as [start(Y-m-01), end(Y-m-t)] pairs (UTC).
     * @return list<array{0:string,1:string}>
     */
    public static function monthBounds(int $nowTs): array
    {
        $curStart = gmdate('Y-m-01', $nowTs);
        $curEnd   = gmdate('Y-m-t', $nowTs);
        $prevTs   = strtotime($curStart . ' -1 day UTC'); // any day in the previous month
        $prevStart = gmdate('Y-m-01', $prevTs);
        $prevEnd   = gmdate('Y-m-t', $prevTs);
        return [[$curStart, $curEnd], [$prevStart, $prevEnd]];
    }
}
