<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\SitesRepository;

/**
 * Recurring fan-out master: every 30 min enqueue one `defyn_sync_site` per
 * schedulable site (active + offline + error). Per spec § 6.3.
 *
 * Each leaf SyncSite job runs independently within Kinsta's 300s PHP budget.
 */
final class SyncAllSites
{
    public const HOOK = 'defyn_sync_all_sites';

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
            as_schedule_single_action(time(), SyncSite::HOOK, [$siteId], 'defyn');
        }
    }
}
