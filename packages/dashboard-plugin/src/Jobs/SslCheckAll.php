<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\SitesRepository;

/**
 * P3.3 — Recurring fan-out master: every 24 h enqueue one `defyn_ssl_check`
 * per schedulable site (active + offline + error).
 *
 * Mirrors HealthPingAll shape — only differences are class name, enqueued HOOK,
 * and cadence (86400 s vs 300 s).
 */
final class SslCheckAll
{
    public const HOOK = 'defyn_ssl_check_all';

    public function __construct(private readonly ?SitesRepository $repo = null) {}

    public function handle(): void
    {
        $repo = $this->repo ?? new SitesRepository();
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        foreach ($repo->findAllSchedulable() as $siteId) {
            as_schedule_single_action(time(), SslCheck::HOOK, [$siteId], 'defyn');
        }
    }
}
