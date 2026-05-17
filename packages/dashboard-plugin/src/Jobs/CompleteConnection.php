<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\Connection;
use Defyn\Dashboard\Services\SitesRepository;

/**
 * Action Scheduler entry point for `defyn_complete_connection`.
 *
 * Thin static wrapper that wires concrete dependencies and delegates to the
 * Connection service (which has its own test coverage). Tests bypass this
 * wrapper by calling Connection directly with mocked dependencies.
 *
 * Hook registration lives in Plugin::boot() so it fires once per request.
 */
final class CompleteConnection
{
    public static function handle(int $siteId, string $code, string $url): void
    {
        $repo = new SitesRepository();
        $site = $repo->findById($siteId);
        if ($site === null) {
            return;  // site was deleted between schedule and execute
        }

        $dashboardPub = (string) $site->ourPublicKey;

        $connection = new Connection(
            httpClient: new SignedHttpClient(),
            repo:       $repo,
            logger:     new ActivityLogger(),
            dashboardPublicKey: $dashboardPub,
        );

        $connection->complete($siteId, $code, $url);
    }
}
