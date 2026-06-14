<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\SslAlertService;

/**
 * P3.3 — Action Scheduler entry point for `defyn_ssl_check`.
 *
 * Thin wrapper that delegates to SslAlertService::evaluate() (Task 8).
 * Plugin::boot() registers HOOK -> handle(). Mirrors HealthPing shape —
 * same delegation pattern, different service.
 */
final class SslCheck
{
    public const HOOK = 'defyn_ssl_check';

    public function __construct(private readonly ?SslAlertService $service = null) {}

    public function handle(int $siteId): void
    {
        ($this->service ?? new SslAlertService())->evaluate($siteId);
    }
}
