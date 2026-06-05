<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/**
 * P2.1 — runs delta sync + writes the plugin_inventory.synced activity event.
 */
final class SyncPluginsService
{
    public function __construct(
        private readonly SitePluginsRepository $repo = new SitePluginsRepository(),
        private readonly ActivityLogger $log = new ActivityLogger(),
    ) {
    }

    /**
     * @param array{plugins?: list<array{slug:string,name:string,version:?string,update_available:bool,update_version:?string}>} $payload
     * @param 'background'|'refresh' $source
     */
    public function sync(int $siteId, array $payload, string $source): void
    {
        $incoming = $payload['plugins'] ?? [];
        $now      = gmdate('Y-m-d H:i:s');

        $this->repo->replaceForSite($siteId, $incoming, $now);

        $updatesAvailable = 0;
        foreach ($incoming as $p) {
            if (!empty($p['update_available'])) {
                $updatesAvailable++;
            }
        }

        // ActivityLogger::log(?int $userId, ?int $siteId, string $eventType, ?array $details, ?string $ip).
        // Background/refresh syncs are user-less; pass null for $userId.
        $this->log->log(null, $siteId, 'plugin_inventory.synced', [
            'plugin_count'            => count($incoming),
            'updates_available_count' => $updatesAvailable,
            'source'                  => $source,
        ]);
    }
}
