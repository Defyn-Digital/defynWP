<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\ConnectionCodesRepository;

/**
 * Hourly cleanup AS job. Sweeps expired or consumed connection-code rows.
 * Per spec § 6.3.
 */
final class CleanupExpiredCodes
{
    public const HOOK = 'defyn_cleanup_expired_codes';

    public function __construct(
        private readonly ?ConnectionCodesRepository $repo = null,
    ) {}

    public function handle(): void
    {
        $repo = $this->repo ?? new ConnectionCodesRepository();
        $repo->deleteExpiredAndConsumed();
    }
}
