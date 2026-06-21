<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\BrokenLinkScanService;

/**
 * P7.1 — Action Scheduler entry point for `defyn_link_scan`.
 *
 * Thin wrapper that delegates to BrokenLinkScanService::scan().
 * Plugin::boot() registers HOOK -> handle(). Mirrors PerformanceScan shape —
 * same delegation pattern, different service.
 */
final class LinkScan
{
    public const HOOK = 'defyn_link_scan';

    public function __construct(private readonly ?BrokenLinkScanService $service = null)
    {
    }

    public function handle(int $siteId): void
    {
        ($this->service ?? new BrokenLinkScanService())->scan($siteId);
    }
}
