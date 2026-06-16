<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\SitesRepository;

/**
 * P6.1 — Recurring fan-out master: every 7 days enqueue one
 * `defyn_performance_scan` per schedulable site (active + offline + error).
 *
 * Mirrors SecurityScanAll shape — but WEEKLY (vs daily) and with NO global
 * feed-refresh step (performance has no shared feed). The only enqueued HOOK
 * is the per-site PerformanceScan leaf.
 */
final class PerformanceScanAll
{
    public const HOOK = 'defyn_performance_scan_all';

    public function __construct(private readonly ?SitesRepository $repo = null)
    {
    }

    public function handle(): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        foreach (($this->repo ?? new SitesRepository())->findAllSchedulable() as $siteId) {
            as_schedule_single_action(time(), PerformanceScan::HOOK, [$siteId], 'defyn');
        }
    }
}
