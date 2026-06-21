<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\SitesRepository;

/**
 * P7.1 — Recurring fan-out master: every 7 days enqueue one
 * `defyn_link_scan` per schedulable site (active + offline + error).
 *
 * Mirrors PerformanceScanAll shape — WEEKLY cadence, no global
 * feed-refresh step (broken-link scanning has no shared feed).
 * The only enqueued HOOK is the per-site LinkScan leaf.
 */
final class LinkScanAll
{
    public const HOOK = 'defyn_link_scan_all';

    public function __construct(private readonly ?SitesRepository $repo = null)
    {
    }

    public function handle(): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        foreach (($this->repo ?? new SitesRepository())->findAllSchedulable() as $siteId) {
            as_schedule_single_action(time(), LinkScan::HOOK, [$siteId], 'defyn');
        }
    }
}
