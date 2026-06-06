<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/**
 * P2.3 — runs delta sync + dangling-failed heal sweep + writes the
 * theme_inventory.synced activity event.
 *
 * Mirrors SyncPluginsService minus the strtok slug-normalization step
 * (themes don't have the plugin_file shape mismatch — `wp_get_themes()`
 * already keys by stylesheet which matches the route regex).
 */
final class SyncThemesService
{
    public function __construct(
        private readonly ThemesRepository $repo = new ThemesRepository(),
        private readonly ActivityLogger $log = new ActivityLogger(),
    ) {
    }

    /**
     * @param array{themes?: list<array{slug:string,name:string,version:?string,parent_slug:?string,is_active:bool,update_available:bool,update_version:?string}>} $payload
     * @param 'background'|'refresh' $source
     */
    public function sync(int $siteId, array $payload, string $source): void
    {
        $incoming = $payload['themes'] ?? [];
        $now      = gmdate('Y-m-d H:i:s');

        $this->repo->replaceForSite($siteId, $incoming, $now);

        // Day-1 heal sweep — see ThemesRepository::healDanglingFailedStates.
        $this->repo->healDanglingFailedStates($siteId, $now);

        $updatesAvailable = 0;
        foreach ($incoming as $t) {
            if (!empty($t['update_available'])) {
                $updatesAvailable++;
            }
        }

        // ActivityLogger::log(?int $userId, ?int $siteId, string $eventType, ?array $details, ?string $ip).
        // Background/refresh syncs are user-less; pass null for $userId.
        $this->log->log(null, $siteId, 'theme_inventory.synced', [
            'theme_count'             => count($incoming),
            'updates_available_count' => $updatesAvailable,
            'source'                  => $source,
        ]);
    }
}
