<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\SyncService;

/**
 * Action Scheduler entry point for `defyn_sync_site`.
 *
 * Thin wrapper that delegates to SyncService (which has its own coverage).
 * Plugin::boot() registers HOOK -> handle(). Tests inject a mocked
 * SyncService; production constructs a real one via the default arg.
 *
 * Differs from F5's CompleteConnection (static handle) because SyncService
 * exposes a constructor-injection surface — this lets tests prove delegation
 * without standing up the full vault + HTTP stack.
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
