<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

/**
 * P6.1 — per-site PageSpeed scan orchestrator.
 *
 * Resolves a site, fetches its mobile + desktop PageSpeed snapshot via
 * PageSpeedClient, stores it (NULL columns for a null strategy), then emits a
 * single `site.performance_measured` activity event.
 *
 * Best-effort (guardrail #2): a missing site is a no-op; if BOTH strategies
 * return null the cycle is skipped (no row, no event). It mirrors
 * VulnerabilityScanService's resolve-site + best-effort shape and NEVER throws
 * into the fan-out.
 */
final class PerformanceScanService
{
    public function __construct(
        private readonly ?SitePerformanceRepository $repo = null,
        private readonly ?SitesRepository $sites = null,
    ) {
    }

    public function scan(int $siteId, ?PageSpeedClient $client = null): void
    {
        $site = ($this->sites ?? new SitesRepository())->findById($siteId);
        if ($site === null) {
            return;
        }
        $client  = $client ?? new PageSpeedClient();
        $mobile  = $client->fetch($site->url, 'mobile');
        $desktop = $client->fetch($site->url, 'desktop');
        if ($mobile === null && $desktop === null) {
            // Both strategies failed. Previously this was a silent no-op, so the
            // UI showed "Not yet measured" forever with no clue why. Log the
            // reason (e.g. missing API key / quota) so it's diagnosable.
            (new ActivityLogger())->log($site->userId, $siteId, 'site.performance_failed', [
                'reason' => $client->lastError ?? 'PageSpeed measurement returned no data.',
            ]);
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        ($this->repo ?? new SitePerformanceRepository())->store($siteId, $mobile, $desktop, $now, $now);
        (new ActivityLogger())->log($site->userId, $siteId, 'site.performance_measured', [
            'mobile_score'  => $mobile['score']  ?? null,
            'desktop_score' => $desktop['score'] ?? null,
        ]);
    }
}
