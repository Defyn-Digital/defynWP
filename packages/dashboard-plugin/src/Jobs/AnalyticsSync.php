<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\AnalyticsScanService;

/**
 * P6.2 — Action Scheduler entry point for `defyn_analytics_sync`.
 *
 * Thin wrapper that delegates to AnalyticsScanService::scan().
 * Plugin::boot() registers HOOK -> handle(). Mirrors PerformanceScan shape —
 * same delegation pattern, different service.
 */
final class AnalyticsSync
{
    public const HOOK = 'defyn_analytics_sync';

    public function __construct(private readonly ?AnalyticsScanService $service = null)
    {
    }

    public function handle(int $siteId): void
    {
        ($this->service ?? new AnalyticsScanService())->scan($siteId);
    }
}
