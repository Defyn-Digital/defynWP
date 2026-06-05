<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SyncPluginsService;
use Throwable;

/**
 * P2.1 — Action Scheduler hook handler for `defyn_refresh_site_plugins`.
 *
 * Scheduled by SitesPluginsRefreshController on operator click.
 * Forces a fresh /plugins/refresh against the connector then runs delta sync.
 * All failures log `plugin_inventory.sync_failed` with `source: refresh`.
 */
final class RefreshSitePlugins
{
    public function __construct(
        private readonly SitesRepository $repo = new SitesRepository(),
        private readonly SignedHttpClient $httpClient = new SignedHttpClient(),
        private readonly SyncPluginsService $syncService = new SyncPluginsService(),
        private readonly ActivityLogger $log = new ActivityLogger(),
    ) {
    }

    public function handle(int $siteId): void
    {
        $site = $this->repo->findById($siteId);
        if ($site === null || $site->status === 'pending') {
            return;
        }

        if ($site->ourPrivateKey === null || $site->ourPrivateKey === '') {
            $this->logFailed($siteId, 'Site is missing its encrypted private key.');
            return;
        }

        $vault = new Vault(DEFYN_VAULT_KEY);
        try {
            $privateKey = $vault->decrypt($site->ourPrivateKey);
        } catch (Throwable $e) {
            $this->logFailed($siteId, 'Failed to decrypt site keypair.');
            return;
        }

        $url           = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/plugins/refresh';
        $canonicalPath = '/defyn-connector/v1/plugins/refresh';

        $response = $this->httpClient->signedPostJson($url, [], $privateKey, $canonicalPath);

        if ($response['error'] !== '') {
            $this->logFailed($siteId, $response['error']);
            return;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->logFailed($siteId, 'Connector returned status ' . $response['status']);
            return;
        }

        $this->syncService->sync($siteId, $response['body'], 'refresh');
    }

    private function logFailed(int $siteId, string $error): void
    {
        // ActivityLogger::log(?int $userId, ?int $siteId, string $eventType, ?array $details).
        // Refresh job runs in Action Scheduler context with no user; pass null for userId.
        $this->log->log(null, $siteId, 'plugin_inventory.sync_failed', [
            'error'  => $error,
            'source' => 'refresh',
        ]);
    }
}
