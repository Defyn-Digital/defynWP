<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\PerformanceScanService;

/**
 * P6.1 — Action Scheduler entry point for `defyn_performance_scan`.
 *
 * Thin wrapper that delegates to PerformanceScanService::scan().
 * Plugin::boot() registers HOOK -> handle(). Mirrors SecurityScan shape —
 * same delegation pattern, different service.
 */
final class PerformanceScan
{
    public const HOOK = 'defyn_performance_scan';

    public function __construct(private readonly ?PerformanceScanService $service = null)
    {
    }

    public function handle(int $siteId): void
    {
        ($this->service ?? new PerformanceScanService())->scan($siteId);
    }
}
