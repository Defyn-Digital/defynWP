<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\SyncService;

/**
 * Action Scheduler entry point for `defyn_sync_site`.
 *
 * Thin wrapper that delegates to SyncService (which has its own coverage).
 * Plugin::boot() registers HOOK -> handle(). Production constructs a real
 * SyncService via the default arg; tests smoke-invoke against a non-existent
 * site id so the service's findById guard early-returns harmlessly (SyncService
 * is final, so substitution via subclass isn't available).
 *
 * Differs from F5's CompleteConnection (static handle) because SyncService
 * exposes a constructor-injection surface — keeps production wiring symmetric
 * with HealthService and leaves room for future test doubles via interface
 * extraction if needed.
 */
final class SyncSite
{
    public const HOOK = 'defyn_sync_site';

    public function __construct(
        private readonly SyncService $service = new SyncService(),
    ) {}

    public function handle(int $siteId): void
    {
        $this->service->sync($siteId);
    }
}
