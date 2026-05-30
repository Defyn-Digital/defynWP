<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\HealthService;

/**
 * Action Scheduler entry point for `defyn_health_ping`.
 *
 * Thin wrapper that delegates to HealthService (which has its own coverage).
 * Plugin::boot() registers HOOK -> handle(). Tests inject a mocked
 * HealthService; production constructs a real one via the default arg.
 *
 * Mirrors SyncSite (Task 15) — same delegation shape, different service.
 */
final class HealthPing
{
    public const HOOK = 'defyn_health_ping';

    public function __construct(
        private readonly HealthService $service = new HealthService(),
    ) {}

    public function handle(int $siteId): void
    {
        $this->service->ping($siteId);
    }
}
