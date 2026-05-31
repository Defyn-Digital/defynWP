<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/**
 * Thin writer for wp_defyn_activity_log. Delegates the actual SQL to
 * ActivityLogRepository (per repo pattern). F9 added the optional
 * $ipAddress argument — REST controllers populate it from
 * $_SERVER['REMOTE_ADDR']; AS jobs leave it null since background jobs
 * have no request context.
 */
final class ActivityLogger
{
    public function __construct(
        private readonly ?ActivityLogRepository $repo = null,
    ) {}

    public function log(?int $userId, ?int $siteId, string $eventType, ?array $details = null, ?string $ipAddress = null): void
    {
        $repo = $this->repo ?? new ActivityLogRepository();
        $repo->insert($userId, $siteId, $eventType, $details, $ipAddress);
    }
}
