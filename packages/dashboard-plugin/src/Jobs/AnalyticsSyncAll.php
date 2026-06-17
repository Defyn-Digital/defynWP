<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\SitesRepository;

/**
 * P6.2 — Recurring fan-out master: every 7 days enqueue one
 * `defyn_analytics_sync` per schedulable site (active + offline + error).
 *
 * SYSTEM cron → findAllSchedulable() is correct (whole fleet, NOT a
 * per-operator endpoint — do not "fix" to findAllForUser). Mirrors
 * PerformanceScanAll shape: WEEKLY, no global feed-refresh step (analytics
 * has no shared feed). The only enqueued HOOK is the per-site AnalyticsSync
 * leaf.
 */
final class AnalyticsSyncAll
{
    public const HOOK = 'defyn_analytics_sync_all';

    public function __construct(private readonly ?SitesRepository $repo = null)
    {
    }

    public function handle(): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        foreach (($this->repo ?? new SitesRepository())->findAllSchedulable() as $siteId) {
            as_schedule_single_action(time(), AnalyticsSync::HOOK, [$siteId], 'defyn');
        }
    }
}
