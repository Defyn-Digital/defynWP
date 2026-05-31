<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\SitesRepository;

/**
 * Recurring fan-out master: every 5 min enqueue one `defyn_health_ping` per
 * schedulable site (active + offline + error). Per spec § 6.3.
 *
 * Mirrors SyncAllSites shape — only differences are class name + enqueued HOOK.
 */
final class HealthPingAll
{
    public const HOOK = 'defyn_health_ping_all';

    public function __construct(
        private readonly ?SitesRepository $repo = null,
    ) {}

    public function handle(): void
    {
        $repo = $this->repo ?? new SitesRepository();
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        foreach ($repo->findAllSchedulable() as $siteId) {
            as_schedule_single_action(time(), HealthPing::HOOK, [$siteId], 'defyn');
        }
    }
}
