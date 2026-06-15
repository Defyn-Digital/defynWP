<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\VulnFeedService;

/**
 * P4.1 — Recurring fan-out master: every 24 h refresh the global vuln feed
 * once, then enqueue one `defyn_security_scan` per schedulable site
 * (active + offline + error).
 *
 * Mirrors SslCheckAll shape — only differences are the one-off feed refresh
 * at the top of the cycle and the enqueued HOOK.
 */
final class SecurityScanAll
{
    public const HOOK = 'defyn_security_scan_all';

    public function __construct(
        private readonly ?SitesRepository $repo = null,
        private readonly ?VulnFeedService $feed = null,
    ) {
    }

    public function handle(): void
    {
        // Refresh the global vuln feed ONCE per cycle (not per-site). Best-effort.
        ($this->feed ?? new VulnFeedService())->refreshIfStale();

        $repo = $this->repo ?? new SitesRepository();
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        foreach ($repo->findAllSchedulable() as $siteId) {
            as_schedule_single_action(time(), SecurityScan::HOOK, [$siteId], 'defyn');
        }
    }
}
