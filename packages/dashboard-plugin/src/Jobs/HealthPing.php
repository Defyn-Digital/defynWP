<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\HealthService;

/**
 * Action Scheduler entry point for `defyn_health_ping`.
 *
 * Thin wrapper that delegates to HealthService (which has its own coverage).
 * Plugin::boot() registers HOOK -> handle(). Production constructs a real
 * HealthService via the default arg; tests smoke-invoke against a non-existent
 * site id so the service's findById guard early-returns harmlessly
 * (HealthService is final, so substitution via subclass isn't available).
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
