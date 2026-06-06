<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SyncThemesService;
use Throwable;

/**
 * P2.3 — Action Scheduler hook handler for `defyn_refresh_site_themes`.
 *
 * Scheduled by SitesThemesRefreshController on operator click or by
 * SyncSite extension on background tick. Forces a fresh /themes/refresh
 * against the connector then runs delta sync. Failures log
 * `site.themes_refresh_failed`.
 *
 * Direct mirror of RefreshSitePlugins (P2.1 Task 8).
 */
final class RefreshSiteThemes
{
    public const HOOK = 'defyn_refresh_site_themes';
    public function __construct(
        private readonly SitesRepository $repo = new SitesRepository(),
        private readonly SignedHttpClient $httpClient = new SignedHttpClient(),
        private readonly SyncThemesService $syncService = new SyncThemesService(),
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
        } catch (Throwable) {
            $this->logFailed($siteId, 'Failed to decrypt site keypair.');
            return;
        }

        $url           = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/themes/refresh';
        $canonicalPath = '/defyn-connector/v1/themes/refresh';

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
        $this->log->log(null, $siteId, 'site.themes_refresh_failed', [
            'error'  => $error,
            'source' => 'refresh',
        ]);
    }
}
