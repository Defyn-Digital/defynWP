<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\Site;
use Defyn\Dashboard\Models\SitePerformance;
use Defyn\Dashboard\Notify\MultiNotifier;
use Defyn\Dashboard\Notify\Notifier;

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
    /** A drop of this many points (or more) vs the previous scan triggers an alert. */
    private const REGRESSION_THRESHOLD = 10;

    public function __construct(
        private readonly ?SitePerformanceRepository $repo = null,
        private readonly ?SitesRepository $sites = null,
        private readonly ?Notifier $notifier = null,
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
        $repo = $this->repo ?? new SitePerformanceRepository();
        $previous = $repo->latestForSite($siteId);

        $now = gmdate('Y-m-d H:i:s');
        $repo->store($siteId, $mobile, $desktop, $now, $now);
        (new ActivityLogger())->log($site->userId, $siteId, 'site.performance_measured', [
            'mobile_score'  => $mobile['score']  ?? null,
            'desktop_score' => $desktop['score'] ?? null,
        ]);

        $this->maybeAlertRegression($site, $siteId, $previous, $mobile, $desktop);
    }

    /**
     * Alert (Slack + email) when a score dropped >= REGRESSION_THRESHOLD points
     * vs the previous scan. Fires on the drop event (compares to the immediately
     * previous scan). Muted sites are logged but not notified.
     *
     * @param array{score:int}|null $mobile
     * @param array{score:int}|null $desktop
     */
    private function maybeAlertRegression(Site $site, int $siteId, ?SitePerformance $previous, ?array $mobile, ?array $desktop): void
    {
        if ($previous === null) {
            return;
        }
        $drops = [];
        $pairs = [
            ['strategy' => 'mobile',  'prev' => $previous->mobileScore,  'new' => $mobile['score']  ?? null],
            ['strategy' => 'desktop', 'prev' => $previous->desktopScore, 'new' => $desktop['score'] ?? null],
        ];
        foreach ($pairs as $pr) {
            if ($pr['prev'] === null || $pr['new'] === null) {
                continue;
            }
            if (((int) $pr['prev'] - (int) $pr['new']) >= self::REGRESSION_THRESHOLD) {
                $drops[] = ['strategy' => $pr['strategy'], 'previous' => (int) $pr['prev'], 'new' => (int) $pr['new']];
            }
        }
        if ($drops === []) {
            return;
        }
        if (!$site->alertsMuted) {
            ($this->notifier ?? new MultiNotifier())->notifyPerformanceRegression($site, $drops);
        }
        (new ActivityLogger())->log($site->userId, $siteId, 'site.performance_regressed', ['drops' => $drops]);
    }
}
