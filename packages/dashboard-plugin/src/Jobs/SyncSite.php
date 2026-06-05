<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SyncPluginsService;
use Defyn\Dashboard\Services\SyncService;
use Throwable;

/**
 * Action Scheduler entry point for `defyn_sync_site`.
 *
 * Delegates the /status sync to SyncService (F6), then — P2.1 — also pulls
 * /plugins and feeds the result to SyncPluginsService. A /plugins failure
 * does NOT mark the site as error (only /status failure does per F6);
 * we log plugin_inventory.sync_failed with source=background and move on.
 *
 * Differs from F5's CompleteConnection (static handle) because SyncService
 * exposes a constructor-injection surface — keeps production wiring symmetric
 * with HealthService.
 */
final class SyncSite
{
    public const HOOK = 'defyn_sync_site';

    public function __construct(
        private readonly SyncService $statusService = new SyncService(),
        private readonly SitesRepository $repo = new SitesRepository(),
        private readonly SignedHttpClient $httpClient = new SignedHttpClient(),
        private readonly SyncPluginsService $pluginsService = new SyncPluginsService(),
        private readonly ActivityLogger $log = new ActivityLogger(),
    ) {}

    public function handle(int $siteId): void
    {
        $this->statusService->sync($siteId);

        // P2.1: also pull /plugins. Best-effort — failures don't mark site as error.
        $site = $this->repo->findById($siteId);
        if ($site === null || $site->status === 'pending') {
            return;
        }
        if ($site->ourPrivateKey === null || $site->ourPrivateKey === '') {
            // SyncService already logged site.sync_failed for the missing-key case
            // OR the site is fine and we just skip plugins.
            return;
        }

        $vault = new Vault(DEFYN_VAULT_KEY);
        try {
            $privateKey = $vault->decrypt($site->ourPrivateKey);
        } catch (Throwable) {
            // SyncService already logged site.sync_failed for the decrypt case
            return;
        }

        $url           = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/plugins';
        $canonicalPath = '/defyn-connector/v1/plugins';

        $response = $this->httpClient->signedGet($url, $privateKey, $canonicalPath);

        if ($response['error'] !== '') {
            $this->logPluginsFailed($siteId, $response['error']);
            return;
        }
        if ($response['status'] === 404) {
            // Connector predates v0.1.3 — no /plugins route registered.
            $this->logPluginsFailed($siteId, 'connector_below_v0.1.3');
            return;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->logPluginsFailed($siteId, 'Connector returned status ' . $response['status']);
            return;
        }

        $this->pluginsService->sync($siteId, $response['body'], 'background');
    }

    private function logPluginsFailed(int $siteId, string $error): void
    {
        // ActivityLogger::log(?int $userId, ?int $siteId, string $eventType, ?array $details).
        $this->log->log(null, $siteId, 'plugin_inventory.sync_failed', [
            'error'  => $error,
            'source' => 'background',
        ]);
    }
}
