<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\VulnerabilityScanService;

/**
 * P4.1 — Action Scheduler entry point for `defyn_security_scan`.
 *
 * Thin wrapper that delegates to VulnerabilityScanService::scan().
 * Plugin::boot() registers HOOK -> handle(). Mirrors SslCheck shape —
 * same delegation pattern, different service.
 */
final class SecurityScan
{
    public const HOOK = 'defyn_security_scan';

    public function __construct(private readonly ?VulnerabilityScanService $service = null)
    {
    }

    public function handle(int $siteId): void
    {
        ($this->service ?? new VulnerabilityScanService())->scan($siteId);
    }
}
